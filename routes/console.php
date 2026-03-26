<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Limpieza nocturna de archivos temporales ──────────────────────────────
// Elimina PDFs temporales generados en pdf-preview/ que tengan más de 2 horas.
// Aunque el sistema ya no genera PDFs en disco, esta tarea previene que
// cualquier archivo temporal futuro se acumule indefinidamente.
Schedule::call(function () {
    $directory = 'public/pdf-preview';

    if (!Storage::exists($directory)) {
        return;
    }

    $files   = Storage::files($directory);
    $deleted = 0;
    $cutoff  = now()->subHours(2)->timestamp;

    foreach ($files as $file) {
        if (Storage::lastModified($file) < $cutoff) {
            Storage::delete($file);
            $deleted++;
        }
    }

    // Eliminar el directorio si quedó vacío
    $remaining = Storage::files($directory);
    if (empty($remaining)) {
        Storage::deleteDirectory($directory);
    }

    if ($deleted > 0) {
        Log::info("Limpieza nocturna: {$deleted} archivo(s) temporal(es) eliminado(s) de pdf-preview/.");
    }
})->hourly()->name('limpiar-pdf-preview')->withoutOverlapping();

// ── Limpieza mensual de comprobantes de depósito ──────────────────────────
// Las imágenes de comprobantes se guardan en storage/app/public/deposits/receipts/.
// Después de 2 meses de subidas ya no tienen valor operativo, así que se
// eliminan para liberar espacio en el VPS.
//
// Estrategia: comparamos receipt_image_uploaded_at (no created_at del depósito)
// para no borrar imágenes que se adjuntaron recientemente a depósitos viejos.
//
// Camino de migración a Cloudflare R2 (cuando el almacenamiento crezca):
//   1. Configurar el disco 'public' → 's3' en config/filesystems.php
//   2. Agregar credenciales R2 en .env (R2 tiene lifecycle rules nativas,
//      así que este scheduled task podría simplificarse o eliminarse)
//   3. Correr: php artisan storage:delete-directory public/deposits/receipts
//   El código del modelo y este task no cambiarían.
Schedule::call(function () {
    $cutoff  = now()->subDays(60);
    $deleted = 0;
    $nulled  = 0;

    \App\Models\Deposit::withTrashed()
        ->whereNotNull('receipt_image')
        ->where('receipt_image_uploaded_at', '<', $cutoff)
        ->chunkById(50, function ($deposits) use (&$deleted, &$nulled) {
            foreach ($deposits as $deposit) {
                if ($deposit->receipt_image && Storage::disk('local')->exists($deposit->receipt_image)) {
                    Storage::disk('local')->delete($deposit->receipt_image);
                    $deleted++;
                }
                // saveQuietly() ya suprime eventos y observers — no necesita withoutEvents().
                $deposit->forceFill([
                    'receipt_image'             => null,
                    'receipt_image_uploaded_at' => null,
                ])->saveQuietly();
                $nulled++;
            }
        });

    if ($deleted > 0) {
        Log::info("Cleanup comprobantes: {$deleted} imagen(es) de depósito eliminada(s) (> 60 días), {$nulled} registro(s) actualizado(s).");
    }
})->dailyAt('03:00')->name('limpiar-comprobantes-deposito')->withoutOverlapping();

// ── Limpieza de activity_log (retención 90 días) ────────────────────────
// Los registros de auditoría mayores a 90 días se eliminan para evitar
// inflar la base de datos. El detalle técnico de importaciones API se
// conserva en api_invoice_imports y api_invoice_import_conflicts.
Schedule::command('activitylog:prune --days=90')
    ->dailyAt('03:30')
    ->name('limpiar-activity-log')
    ->withoutOverlapping();