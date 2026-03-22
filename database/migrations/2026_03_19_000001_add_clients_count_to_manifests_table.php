<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            // Cantidad de clientes únicos (por RTN) en el manifiesto.
            // Se mantiene desnormalizado junto con invoices_count y returns_count
            // para consultas O(1) sin subqueries en la tabla principal.
            $table->unsignedInteger('clients_count')->default(0)->after('returns_count');
        });

        // Backfill: recalcular para todos los manifiestos existentes.
        // COUNT(DISTINCT NULLIF(client_rtn, '')) excluye RTNs vacíos/nulos
        // para no contar como un "cliente" las facturas sin RTN.
        DB::statement("
            UPDATE manifests m
            SET clients_count = (
                SELECT COUNT(DISTINCT NULLIF(i.client_rtn, ''))
                FROM invoices i
                WHERE i.manifest_id = m.id
                  AND i.deleted_at IS NULL
                  AND i.client_rtn IS NOT NULL
                  AND i.client_rtn != ''
            )
            WHERE m.deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->dropColumn('clients_count');
        });
    }
};
