<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega columna pre-calculada `total_returns` a facturas.
 *
 * Problema: el accessor `getTotalReturnsAttribute()` ejecuta
 * `$this->returns()->sum('total')` — una query por cada factura.
 * En un listado de 100 facturas = 100 queries extras (N+1).
 *
 * Solución: columna desnormalizada que se actualiza cada vez que
 * se crea, edita, aprueba, rechaza o elimina una devolución.
 * Esto convierte el acceso de O(n) queries a O(1).
 *
 * Semántica: suma de devoluciones aprobadas + pendientes (no rechazadas).
 * Las rechazadas significan que la mercadería NO se devolvió, por lo
 * tanto no deben reducir el saldo del cliente.
 *
 * NOTA: el accessor original sumaba TODAS las devoluciones incluyendo
 * rechazadas. Esta migración corrige esa inconsistencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('total_returns', 12, 2)->default(0)->after('total');
        });

        // ── Backfill: calcular total_returns para facturas existentes ────
        // Una sola query UPDATE...FROM en vez de iterar por PHP.
        // Solo suma devoluciones aprobadas + pendientes (no rechazadas).
        DB::statement("
            UPDATE invoices
            SET total_returns = sub.sum_returns
            FROM (
                SELECT invoice_id, COALESCE(SUM(total), 0) AS sum_returns
                FROM returns
                WHERE deleted_at IS NULL
                  AND status IN ('approved', 'pending')
                GROUP BY invoice_id
            ) AS sub
            WHERE invoices.id = sub.invoice_id
        ");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('total_returns');
        });
    }
};
