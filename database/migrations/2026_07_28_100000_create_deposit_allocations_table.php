<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aplicación de pagos: un depósito bancario se reparte entre N manifiestos.
 *
 * POR QUÉ EXISTE
 * ──────────────
 * Hasta ahora `deposits.manifest_id` era 1:1 — una boleta bancaria cubría
 * exactamente un manifiesto. La operación real no funciona así: para no
 * hacer dos transferencias, el encargado manda UNA sola boleta que cubre el
 * saldo de dos o más manifiestos. Además, los depósitos rara vez cuadran al
 * centavo, y el excedente de uno debe poder tapar los centavos que faltan en
 * manifiestos anteriores.
 *
 * Esta tabla separa las dos cosas que antes estaban fundidas:
 *   - `deposits.amount`          = dinero que entró al banco (la boleta)
 *   - `deposit_allocations`      = cómo se reparte ese dinero entre manifiestos
 *
 * INVARIANTE FUERTE
 * ─────────────────
 *   SUM(allocations WHERE deposit_id = D) == deposits.amount   (siempre)
 *
 * No existe "dinero sin aplicar": si sobra después de llenar todos los
 * manifiestos candidatos, el remanente se carga al manifiesto de origen
 * (dejando su difference en negativo) y exige justificación. Ver
 * DepositAllocationService.
 *
 * COMPATIBILIDAD
 * ──────────────
 * `deposits.manifest_id` se conserva y pasa a significar "manifiesto desde
 * el que se registró el depósito". Todo el código que hace $deposit->manifest
 * sigue funcionando; DepositPolicy y Deposit::scopeVisibleTo no se tocan.
 *
 * ÍNDICES
 * ───────
 * - UNIQUE(deposit_id, manifest_id): un depósito aporta UNA línea por
 *   manifiesto. Sin esto, el reparto podría duplicar filas ante un reintento
 *   y sobre-acreditar el manifiesto.
 * - INDEX(manifest_id): Manifest::recalculateTotals() suma por manifiesto en
 *   cada devolución, depósito e importación. Es la query caliente de la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_allocations', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete: si un depósito se borra permanentemente
            // (forceDelete de super_admin), su reparto no tiene sentido
            // sin él y debe irse con él. No hay riesgo de perder auditoría:
            // el forceDelete ya deja constancia en activity_log.
            $table->foreignId('deposit_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: un manifiesto con dinero aplicado NO puede
            // borrarse. Mismo criterio que deposits.manifest_id.
            $table->foreignId('manifest_id')->constrained()->restrictOnDelete();

            $table->decimal('amount', 12, 2);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['deposit_id', 'manifest_id']);
            $table->index('manifest_id');
        });

        // Constraint a nivel BD: una aplicación de monto cero o negativo es
        // data corrupta, no un caso de negocio. La app ya lo valida, pero la
        // BD es la última línea de defensa contra un bug futuro o un INSERT
        // manual en producción.
        DB::statement(
            'ALTER TABLE deposit_allocations ADD CONSTRAINT deposit_allocations_amount_positive CHECK (amount > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_allocations');
    }
};
