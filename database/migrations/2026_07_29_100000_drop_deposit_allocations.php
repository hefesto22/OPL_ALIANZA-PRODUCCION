<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revierte el modelo de aplicación de pagos (deposit_allocations).
 *
 * ─────────────────────────────────────────────────────────────────────
 *  POR QUÉ SE QUITA
 * ─────────────────────────────────────────────────────────────────────
 *  La tabla existía para repartir una boleta bancaria entre varios
 *  manifiestos: el excedente barría deudas viejas y chicas de la misma
 *  bodega. La operación evaluó ese comportamiento y decidió no quererlo —
 *  prefieren que un depósito se aplique íntegro al manifiesto donde se
 *  registra, y que el exceso quede visible como SOBREPAGO en ese mismo
 *  manifiesto en vez de moverse solo a otro lado.
 *
 *  Sin el reparto, cada depósito tendría siempre exactamente una fila en
 *  deposit_allocations, idéntica al propio depósito: cero información y una
 *  tabla más que mantener. Se elimina.
 *
 *  Las migraciones que la crearon se conservan en el repo a propósito: la
 *  historia de la base debe poder reproducirse tal como ocurrió. En un
 *  entorno nuevo se crea y se destruye en la misma corrida, que cuesta
 *  milisegundos; reescribir el pasado costaría mucho más el día que haya
 *  que auditar por qué una base quedó como quedó.
 *
 *  Lo que SÍ se conserva del trabajo: la justificación obligatoria al
 *  depositar de más, el cierre de manifiestos sobrepagados y el ajuste de
 *  centavos. Nada de eso dependía de esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('deposit_allocations');

        if (Schema::hasColumn('deposits', 'allocated_amount')) {
            Schema::table('deposits', function (Blueprint $table) {
                $table->dropColumn('allocated_amount');
            });
        }
    }

    /**
     * Recrea la estructura vacía. Los repartos originales NO se restauran:
     * eran derivables 1:1 de deposits (un depósito, su manifiesto, su monto),
     * así que reconstruirlos es un INSERT ... SELECT trivial si algún día
     * hiciera falta.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('deposits', 'allocated_amount')) {
            Schema::table('deposits', function (Blueprint $table) {
                $table->decimal('allocated_amount', 12, 2)->default(0)->after('amount');
            });
        }

        if (Schema::hasTable('deposit_allocations')) {
            return;
        }

        Schema::create('deposit_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manifest_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['deposit_id', 'manifest_id']);
            $table->index('manifest_id');
        });

        DB::statement(
            'ALTER TABLE deposit_allocations ADD CONSTRAINT deposit_allocations_amount_positive CHECK (amount > 0)'
        );
    }
};
