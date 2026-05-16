<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cancelación auditable de depósitos.
 *
 * Hasta ahora "borrar" un depósito hacía soft-delete (deleted_at) sin razón
 * documentada ni quién lo cancelaba. Para operación financiera real eso es
 * insuficiente — el equipo necesita saber por qué se canceló un depósito
 * para responder en auditorías o reabrir el caso si fue error humano.
 *
 * Replica el patrón ya establecido en `returns` (migración 2026-04-14):
 *   - cancelled_at:        timestamp del momento de cancelación.
 *   - cancelled_by:        FK a users; nullable para tolerar borrado del user.
 *   - cancellation_reason: texto libre (máx 500) — el motivo se valida en el
 *                          form del modal de Filament.
 *
 * Index parcial WHERE cancelled_at IS NOT NULL: filtra la tab "Cancelados"
 * en milisegundos sobre millones de filas sin penalizar la query de
 * "Activos" (que no toca el index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('observations');
            $table->foreignId('cancelled_by')
                ->nullable()
                ->after('cancelled_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by');
        });

        DB::statement(
            'CREATE INDEX deposits_cancelled_at_idx ON deposits (cancelled_at) WHERE cancelled_at IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS deposits_cancelled_at_idx');

        Schema::table('deposits', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });
    }
};
