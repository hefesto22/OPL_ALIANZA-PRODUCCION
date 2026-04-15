<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Elimina registros de activity_log mayores a N días.
 *
 * Diseñado para ejecutarse diariamente via scheduler.
 * Usa DELETE con LIMIT en batches para no bloquear la tabla
 * en caso de tener miles de registros acumulados.
 *
 * Uso manual:
 *   php artisan activitylog:prune              → borra > 90 días (default)
 *   php artisan activitylog:prune --days=60    → borra > 60 días
 */
class PruneActivityLog extends Command
{
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
