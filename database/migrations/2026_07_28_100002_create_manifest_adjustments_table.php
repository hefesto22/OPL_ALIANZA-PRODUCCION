<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajuste de centavos auditable.
 *
 * EL PROBLEMA QUE RESUELVE
 * ────────────────────────
 * El reparto FIFO (deposit_allocations) tapa los centavos que FALTAN: llega
 * dinero real y se aplica al manifiesto viejo. Pero NO puede resolver los
 * que SOBRAN — un manifiesto con difference = -0.01 ya recibió de más, no
 * hay plata que mover. Como isReadyToClose() exige difference == 0 exacto,
 * esos manifiestos quedan varados para siempre (en producción al 28/07/2026:
 * 114 manifiestos activos, 0 cerrados).
 *
 * POR QUÉ UN REGISTRO Y NO UNA TOLERANCIA
 * ───────────────────────────────────────
 * La alternativa era dejar cerrar cuando |difference| <= 0.05. Se descartó:
 * un cierre automático no deja constancia de quién lo autorizó. Acá cada
 * centavo lo firma alguien, con nombre, motivo y hora — y isReadyToClose()
 * no se toca: sigue exigiendo cero exacto. El ajuste es simplemente la vía
 * auditable de llegar a cero.
 *
 * `amount` puede ser NEGATIVO: cubre tanto el faltante (+0.32) como el
 * sobrante (-0.01). El tope absoluto lo impone ManifestAdjustmentService
 * vía config('manifests.ajustes.tope_hnl') — no la BD, para poder subirlo
 * sin migración si la operación lo pide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifest_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('manifest_id');
        });

        Schema::table('manifests', function (Blueprint $table) {
            // Cache de SUM(manifest_adjustments.amount). Entra en el cálculo:
            //   difference = total_to_deposit - total_deposited - adjustment_amount
            // Se mantiene separada de total_deposited a propósito: la columna
            // "Depositado" debe seguir reflejando plata real en el banco. El
            // ajuste se muestra aparte en la UI.
            $table->decimal('adjustment_amount', 12, 2)->default(0)->after('total_deposited');
        });
    }

    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->dropColumn('adjustment_amount');
        });

        Schema::dropIfExists('manifest_adjustments');
    }
};
