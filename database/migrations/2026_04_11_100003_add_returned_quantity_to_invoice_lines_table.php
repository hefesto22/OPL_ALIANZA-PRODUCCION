<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega columna pre-calculada `returned_quantity` a invoice_lines.
 *
 * Problema: el accessor `getReturnedQuantityAttribute()` ejecutaba
 * `$this->returnLines()->sum('quantity')` — una query N+1 por cada
 * línea de factura. Además tenía un bug: solo sumaba unidades sueltas
 * sin considerar `quantity_box × conversion_factor` para productos
 * vendidos en caja (CJ).
 *
 * Solución: columna desnormalizada que almacena la cantidad total
 * devuelta EN FRACCIONES: (cajas × factor_conversión) + unidades.
 * Se actualiza en ReturnService cada vez que una devolución cambia.
 *
 * Fórmula: returned_quantity = SUM(rl.quantity_box × MAX(il.conversion_factor, 1) + rl.quantity)
 *          solo para devoluciones con status IN ('approved', 'pending')
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->decimal('returned_quantity', 10, 4)->default(0)->after('total');
        });

        // ── Backfill: calcular returned_quantity para líneas existentes ──
        // Una sola query UPDATE...FROM con la fórmula correcta (cajas × factor + unidades).
        // Solo cuenta devoluciones aprobadas + pendientes (no rechazadas ni eliminadas).
        DB::statement("
            UPDATE invoice_lines
            SET returned_quantity = sub.total_returned
            FROM (
                SELECT
                    rl.invoice_line_id,
                    COALESCE(SUM(
                        rl.quantity_box * (
                            CASE WHEN COALESCE(il.conversion_factor, 1) < 1
                                 THEN 1
                                 ELSE COALESCE(il.conversion_factor, 1)
                            END
                        ) + rl.quantity
                    ), 0) AS total_returned
                FROM return_lines rl
                JOIN invoice_lines il ON rl.invoice_line_id = il.id
                JOIN returns r ON rl.return_id = r.id
                WHERE r.deleted_at IS NULL
                  AND r.status IN ('approved', 'pending')
                GROUP BY rl.invoice_line_id
            ) AS sub
            WHERE invoice_lines.id = sub.invoice_line_id
        ");
    }

    public function down(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });
    }
};
