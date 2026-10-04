<?php

namespace App\Console\Commands;

use App\Support\Edt\EdtModule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Elimina registros de activity_log mayores a N días.
 *
 * Diseñado para ejecutarse diariamente via scheduler.
 * Usa DELETE con LIMIT en batches para no bloquear la tabla
 * en caso de tener miles de registros acumulados.
 *
 * Bitácoras permanentes: los log_name de PERMANENT_LOG_NAMES nunca se
 * borran, sin importar su antigüedad. Hoy solo la del módulo EDT, que
 * guarda el historial de cambios del catálogo (proveedores, productos,
 * precios) y se consulta meses después. Todo lo demás se sigue borrando
 * a los N días como siempre.
 *
 * Uso manual:
 *   php artisan activitylog:prune              → borra > 90 días (default)
 *   php artisan activitylog:prune --days=60    → borra > 60 días
 */
class PruneActivityLog extends Command
{
    /**
     * log_name que la limpieza no toca nunca.
     *
     * @var array<int, string>
     */
    public const PERMANENT_LOG_NAMES = [
        EdtModule::LOG_NAME,
    ];

    protected $signature = 'activitylog:prune {--days=90 : Días de retención}';

    protected $description = 'Elimina registros de activity_log mayores al período de retención';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days)->toDateTimeString();
        $batchSize = 1000;
        $total = 0;

        $this->info("Eliminando registros de activity_log anteriores a {$cutoff} ({$days} días)...");

        // Borrar en batches para no bloquear la tabla
        do {
            $deleted = DB::table('activity_log')
                ->where('created_at', '<', $cutoff)
                // whereNull explícito: en SQL `NULL NOT IN (...)` no es true,
                // así que sin él las filas sin log_name dejarían de borrarse.
                ->where(fn ($query) => $query
                    ->whereNull('log_name')
                    ->orWhereNotIn('log_name', self::PERMANENT_LOG_NAMES))
                ->limit($batchSize)
                ->delete();

            $total += $deleted;

            if ($deleted > 0) {
                $this->line("  → {$total} registros eliminados...");
            }
        } while ($deleted === $batchSize);

        if ($total > 0) {
            $this->info("Limpieza completada: {$total} registros eliminados.");
        } else {
            $this->info('No hay registros antiguos para eliminar.');
        }

        return self::SUCCESS;
    }
}
