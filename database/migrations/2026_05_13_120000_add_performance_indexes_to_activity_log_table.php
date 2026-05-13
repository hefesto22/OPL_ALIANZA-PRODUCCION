<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de performance para la tabla activity_log.
 *
 * Contexto del problema:
 *   ActivityLogResource (panel de Administración → Registros de Actividad)
 *   hace `defaultSort('created_at', 'desc')` y ofrece filtros de rango por
 *   fecha y de tipo (log_name). La tabla nace con índice solo en log_name
 *   y en los morphs de subject/causer. Sin índice en created_at, cada
 *   apertura del panel hace un seq scan completo de la tabla — escalando a
 *   ~30k-50k filas/día con el objetivo de 10k facturas/día, eso se vuelve
 *   prohibitivo en pocas semanas.
 *
 * Qué agregamos:
 *   1. INDEX simple sobre `created_at` — soporta el ORDER BY DESC LIMIT del
 *      listado paginado por defecto. B-tree (no BRIN) porque BRIN no
 *      acelera ORDER BY DESC LIMIT, que es nuestro patrón crítico.
 *   2. INDEX compuesto sobre `(log_name, created_at)` — soporta los filtros
 *      combinados "tipo de log" + rango de fechas. También sirve como
 *      prefijo para queries que solo filtran por log_name (Postgres puede
 *      usar la parte izquierda del índice compuesto).
 *
 * Por qué SIN `CONCURRENTLY`:
 *   El sistema está en pre-producción (infraestructura desplegada pero sin
 *   usuarios reales operando). La tabla está vacía o con datos de prueba.
 *   La migración corre en milisegundos, sin bloquear nada. Cuando empiece
 *   el uso real, futuras migraciones sobre tablas grandes SÍ deberán usar
 *   `DB::statement('CREATE INDEX CONCURRENTLY ...')` (sección 20 del CLAUDE.md).
 *
 * Lo que NO se aborda aquí (deuda 🟡 documentada):
 *   La búsqueda LIKE '%xxx%' sobre `description` en el Resource sigue siendo
 *   seq scan. Acelerarla requiere extensión pg_trgm + índice GIN. Decisión
 *   diferida: lo abordamos cuando description se vuelva un patrón de query
 *   frecuente del operador, no del admin esporádico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table) {
                // Listado paginado del Resource: ORDER BY created_at DESC LIMIT N
                $table->index('created_at', 'activity_log_created_at_index');

                // Filtros combinados: WHERE log_name = ? AND created_at BETWEEN ? AND ?
                $table->index(
                    ['log_name', 'created_at'],
                    'activity_log_log_name_created_at_index'
                );
            });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->dropIndex('activity_log_log_name_created_at_index');
                $table->dropIndex('activity_log_created_at_index');
            });
    }
};
