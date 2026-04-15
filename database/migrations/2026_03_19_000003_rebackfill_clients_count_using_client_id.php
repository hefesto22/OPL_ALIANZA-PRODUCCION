<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-calcula clients_count usando client_id (ID interno de Jaremar)
     * en lugar de client_rtn (RTN fiscal).
     *
     * Motivo del cambio: client_id siempre viene del API de Jaremar porque
     * es su propio identificador interno. El RTN puede estar vacío para
     * clientes sin registro fiscal, lo que produciría un conteo incorrecto.
     */
    public function up(): void
    {
        // Recalcular en manifests (nivel global).
        DB::statement('
            UPDATE manifests m
            SET clients_count = (
                SELECT COUNT(DISTINCT i.client_id)
                FROM invoices i
                WHERE i.manifest_id = m.id
                  AND i.deleted_at  IS NULL
                  AND i.client_id   IS NOT NULL
            )
            WHERE m.deleted_at IS NULL
        ');

        // Recalcular en manifest_warehouse_totals (nivel por bodega).
        DB::statement('
            UPDATE manifest_warehouse_totals mwt
            SET clients_count = (
                SELECT COUNT(DISTINCT i.client_id)
                FROM invoices i
                WHERE i.manifest_id  = mwt.manifest_id
                  AND i.warehouse_id = mwt.warehouse_id
                  AND i.deleted_at   IS NULL
                  AND i.client_id    IS NOT NULL
            )
        ');
    }

    public function down(): void
    {
        // Revertir al conteo por RTN si se hace rollback.
        DB::statement("
            UPDATE manifests m
            SET clients_count = (
                SELECT COUNT(DISTINCT NULLIF(i.client_rtn, ''))
                FROM invoices i
                WHERE i.manifest_id = m.id
                  AND i.deleted_at  IS NULL
                  AND i.client_rtn  IS NOT NULL
                  AND i.client_rtn  != ''
            )
            WHERE m.deleted_at IS NULL
        ");

        DB::statement("
            UPDATE manifest_warehouse_totals mwt
            SET clients_count = (
                SELECT COUNT(DISTINCT NULLIF(i.client_rtn, ''))
                FROM invoices i
                WHERE i.manifest_id  = mwt.manifest_id
                  AND i.warehouse_id = mwt.warehouse_id
                  AND i.deleted_at   IS NULL
                  AND i.client_rtn   IS NOT NULL
                  AND i.client_rtn   != ''
            )
        ");
    }
};
