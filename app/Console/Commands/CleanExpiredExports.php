<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Elimina archivos de exportación Excel que no fueron descargados.
 *
 * Las exportaciones generadas vía ShouldQueue se almacenan en
 * storage/app/exports/ y se eliminan al descargarse (deleteFileAfterSend).
 * Si el usuario borra la notificación sin descargar o simplemente la ignora,
 * el archivo queda huérfano. Este comando limpia esos archivos.
 *
 * Diseñado para ejecutarse diariamente vía scheduler.
 *
 * Uso manual:
 *   php artisan exports:clean              → borra > 24 horas (default)
 *   php artisan exports:clean --hours=48   → borra > 48 horas
 */
class CleanExpiredExports extends Command
{
    protected $signature = 'exports:clean {--hours=24 : Horas de retención}';

    protected $description = 'Elimina exportaciones Excel no descargadas que superaron el período de retención';

    public function handle(): int
    {
        $hours  = (int) $this->option('hours');
        $cutoff = now()->subHours($hours)->timestamp;
        $disk   = Storage::disk('local');

        if (!$disk->exists('exports')) {
            $this->info('No hay directorio de exportaciones. Nada que limpiar.');
            return self::SUCCESS;
        }

        $files   = $disk->files('exports');
        $deleted = 0;

        foreach ($files as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->info("Limpieza completada: {$deleted} exportación(es) eliminada(s) (> {$hours}h).");
        } else {
            $this->info('No hay exportaciones expiradas.');
        }

        return self::SUCCESS;
    }
}
