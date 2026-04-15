<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('jaremar_id', 10)->nullable(); // idConcepto de Jaremar
            $table->string('code', 10);                  // BE-01, PNC-01, GEN-01
            $table->enum('category', ['BE', 'PNC', 'GEN']); // Bodega/Entrega, Prod No Conforme, General
            $table->string('description');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_reasons');
    }
};
