<?php
// database/migrations/2026_03_05_000003_create_manifest_warehouse_totals_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifest_warehouse_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manifest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();

            // Totales por bodega
            $table->decimal('total_invoices', 12, 2)->default(0);
            $table->decimal('total_returns', 12, 2)->default(0);
            $table->decimal('total_to_deposit', 12, 2)->default(0);
            $table->decimal('total_deposited', 12, 2)->default(0);
            $table->decimal('difference', 12, 2)->default(0);
            $table->unsignedInteger('invoices_count')->default(0);
            $table->unsignedInteger('returns_count')->default(0);

            $table->timestamps();

            // Un registro por manifiesto+bodega
            $table->unique(['manifest_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifest_warehouse_totals');
    }
};