<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PrintReportsController::returns() ordena por return_date desc y acepta
     * filtros whereDate('return_date', ...). Sin este índice, Postgres hace
     * un sequential scan + filesort sobre toda la tabla returns cada vez
     * que se genera el reporte de devoluciones.
     */
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->index('return_date', 'returns_return_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropIndex('returns_return_date_idx');
        });
    }
};
