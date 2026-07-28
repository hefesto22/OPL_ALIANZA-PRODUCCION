<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: una allocation por cada depósito existente.
 *
 * Traduce el modelo viejo (1 depósito = 1 manifiesto) al nuevo sin cambiar
 * un solo número: cada depósito histórico genera exactamente una línea de
 * reparto por su monto completo, hacia su manifest_id actual.
 *
 * SE COPIAN TAMBIÉN LOS CANCELADOS Y SOFT-DELETED. La exclusión de esos
 * montos ocurre al LEER (el join filtra cancelled_at/deleted_at), no al
 * escribir. Si mañana se restaura un depósito cancelado, su reparto sigue
 * ahí y vuelve a contar solo — sin backfill de emergencia.
 *
 * INSERT ... SELECT en un solo statement en vez de chunks en PHP: no carga
 * filas en memoria del proceso y es atómico. Postgres soporta DDL+DML
 * transaccional, así que si la verificación de paridad falla al final, TODA
 * la migración hace rollback y la base queda como estaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ON CONFLICT DO NOTHING: idempotente. Si la migración se re-corre
        // sobre una base que ya tiene el reparto, no duplica ni explota.
        DB::statement('
            INSERT INTO deposit_allocations (deposit_id, manifest_id, amount, created_by, created_at, updated_at)
            SELECT id, manifest_id, amount, created_by, COALESCE(created_at, NOW()), NOW()
            FROM deposits
            ON CONFLICT (deposit_id, manifest_id) DO NOTHING
        ');

        DB::statement('UPDATE deposits SET allocated_amount = amount');

        $this->assertParity();
    }

    /**
     * Verifica que el total depositado de CADA manifiesto sea idéntico
     * calculado por el método viejo (SUM de deposits) y por el nuevo
     * (SUM de allocations). Un solo manifiesto desalineado aborta la
     * migración completa — es dinero, no se tolera "casi igual".
     */
    private function assertParity(): void
    {
        $mismatches = DB::select('
            SELECT m.id,
                   m.number,
                   COALESCE(viejo.total, 0) AS viejo,
                   COALESCE(nuevo.total, 0) AS nuevo
            FROM manifests m
            LEFT JOIN (
                SELECT manifest_id, SUM(amount) AS total
                FROM deposits
                WHERE cancelled_at IS NULL AND deleted_at IS NULL
                GROUP BY manifest_id
            ) viejo ON viejo.manifest_id = m.id
            LEFT JOIN (
                SELECT da.manifest_id, SUM(da.amount) AS total
                FROM deposit_allocations da
                JOIN deposits d ON d.id = da.deposit_id
                WHERE d.cancelled_at IS NULL AND d.deleted_at IS NULL
                GROUP BY da.manifest_id
            ) nuevo ON nuevo.manifest_id = m.id
            WHERE COALESCE(viejo.total, 0) <> COALESCE(nuevo.total, 0)
        ');

        if ($mismatches !== []) {
            $detalle = collect($mismatches)
                ->take(10)
                ->map(fn ($r) => "#{$r->number}: viejo={$r->viejo} nuevo={$r->nuevo}")
                ->implode(' | ');

            throw new \RuntimeException(
                'Backfill de deposit_allocations ABORTADO: '.count($mismatches).
                ' manifiesto(s) con total depositado distinto tras la migración. '.
                'Primeros casos → '.$detalle
            );
        }
    }

    public function down(): void
    {
        DB::statement('TRUNCATE deposit_allocations');
        DB::statement('UPDATE deposits SET allocated_amount = 0');
    }
};
