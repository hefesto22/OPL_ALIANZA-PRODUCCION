<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índice compuesto (warehouse_id, date) sobre manifests.
     *
     * Soporta los patrones de query más frecuentes de la reportería diaria:
     *   - "Manifiestos de hoy por bodega" (dashboard de operación)
     *   - "Manifiestos de la semana por bodega" (cierre semanal)
     *   - Filtros Filament por bodega + rango de fechas
     *
     * El orden warehouse_id → date es deliberado: warehouse_id es el filtro
     * MÁS selectivo (3 valores únicos para 3 bodegas) seguido de fechas que
     * van creciendo. Postgres puede usar el prefix del índice para queries
     * que solo filtran por warehouse_id, y el índice completo cuando ambos
     * aparecen.
     *
     * Pre-producción: aplicación directa sin CONCURRENTLY. En producción
     * con millones de filas habría que cambiarlo por:
     *   DB::statement('CREATE INDEX CONCURRENTLY ...')
     * para no bloquear escrituras durante la creación.
     */
    public function up(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->index(
                ['warehouse_id', 'date'],
                'manifests_warehouse_date_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('manifests', function (Blueprint $table) {
            $table->dropIndex('manifests_warehouse_date_idx');
        });
    }
};
