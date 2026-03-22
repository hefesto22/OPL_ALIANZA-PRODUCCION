<?php
// database/migrations/2026_03_05_000001_add_warehouse_id_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('is_active')
                ->constrained()
                ->nullOnDelete();
            // null = admin o Haremar (ve todo)
            // valor = usuario de bodega (ve solo su bodega)
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};