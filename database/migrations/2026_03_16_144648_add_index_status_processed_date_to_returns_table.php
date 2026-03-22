<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            // Índice compuesto para la query principal de Jaremar:
            // WHERE status = 'approved' AND processed_date = ?
            // El orden importa: status primero (cardinalidad baja = filtra más rápido)
            $table->index(['status', 'processed_date'], 'returns_status_processed_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropIndex('returns_status_processed_date_idx');
        });
    }
};