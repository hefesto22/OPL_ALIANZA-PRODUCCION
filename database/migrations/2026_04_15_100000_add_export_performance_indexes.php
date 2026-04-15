<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices adicionales específicos para los filtros de exportación.
 *
 * Justificación por índice:
 *
 * 1. deposits.deposit_date → los exports de depósitos filtran con
 *    whereDate('deposit_date', '>=/'<=' ...). Sin índice = seq scan
 *    sobre toda la tabla cada vez que un admin exporta un período.
 *
 * 2. deposits[manifest_id, deposit_date] → query de cierre de manifiesto
 *    (listar depósitos de un manifiesto ordenados por fecha). El índice
 *    simple de manifest_id ya existe, pero el compuesto evita un sort
 *    extra en PostgreSQL cuando se consulta ordenado por fecha.
 *
 * 3. returns[status, return_date] → dashboard y reportes de devoluciones
 *    filtran por status + rango de fechas. Ya existe un compuesto
 *    [warehouse_id, return_date] pero NO cubre queries que no filtran
 *    por bodega (reportes globales de admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Filtro directo de exports de depósitos por rango de fecha.
        Schema::table('deposits', function (Blueprint $table) {
            $table->index('deposit_date', 'idx_deposits_deposit_date');
            $table->index(['manifest_id', 'deposit_date'], 'idx_deposits_manifest_date');
        });

        // Filtro de exports/reportes de devoluciones por status + fecha
        // (para queries globales sin filtro de bodega).
        Schema::table('returns', function (Blueprint $table) {
            $table->index(['status', 'return_date'], 'idx_returns_status_return_date');
        });
    }

    public function down(): void
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropIndex('idx_deposits_deposit_date');
            $table->dropIndex('idx_deposits_manifest_date');
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->dropIndex('idx_returns_status_return_date');
        });
    }
};
