<?php
// database/migrations/2026_03_05_000002_update_warehouse_id_nullable_in_manifests_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            // Quitar constraint anterior y hacerlo nullable
            $table->dropForeign(['warehouse_id']);
            $table->foreignId('warehouse_id')
                ->nullable()
                ->change()
                ->constrained()
                ->nullOnDelete();
            // null = manifiesto sin bodega principal asignada (admin lo ve siempre)
        });
    }

    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->foreignId('warehouse_id')
                ->change()
                ->constrained()
                ->restrictOnDelete();
        });
    }
};