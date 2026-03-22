<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifest_warehouse_totals', function (Blueprint $table) {
            // Clientes únicos por bodega dentro del manifiesto.
            // Necesario para usuarios de bodega que solo ven su slice del manifiesto.
            $table->unsignedInteger('clients_count')->default(0)->after('returns_count');
        });

        // Backfill: calcular por combinación manifest + warehouse.
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

    public function down(): void
    {
        Schema::table('manifest_warehouse_totals', function (Blueprint $table) {
            $table->dropColumn('clients_count');
        });
    }
};
