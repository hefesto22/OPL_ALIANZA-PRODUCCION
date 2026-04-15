<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('code', 20)->unique(); // 230, 231, etc.
            $table->string('name')->nullable();
            $table->string('seller_id')->nullable();   // Vendedorid de Jaremar
            $table->string('seller_name')->nullable(); // Vendedor de Jaremar
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
