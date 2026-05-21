<?php

namespace App\Console\Commands;

use App\Models\Manifest;
use Illuminate\Console\Command;

/**
 * Backfill de manifests.date para manifiestos creados antes de que la
 * regla "derivar fecha de FechaFactura" se introdujera al sistema.
 *
 * Contexto: hasta el ManifestDateValidator, createManifest() asignaba
 * manifests.date = now()->toDateString(). Esto producía desfase entre
 * la fecha operativa real (max(invoice_date) del grupo) y la fecha de
 * captura. Este comando corrige los manifests pre-existentes.
 *
 * Es seguro correrlo en cualquier momento — idempotente:
 *   - Solo actualiza si manifests.date != max(invoice_date)
 *   - Manifests sin facturas se omiten
 *   - chunkById O(1) memoria — funciona con miles o millones de filas
 *
 * Uso:
 *   php artisan manifests:backfill-operation-dates --dry-run
 *   php artisan manifests:backfill-operation-dates
 *   php artisan manifests:backfill-operation-dates --chunk=1000
 *
 * Pensado como PLANTILLA reusable para futuros backfills similares.
 */
class BackfillManifestOperationDates extends Command
{
    protected $signature = 'manifests:backfill-operation-dates
                            {--dry-run : Mostrar qué se haría sin escribir a BD}
                            {--chunk=500 : Tamaño de chunk para chunkById}';

    protected $description = 'Recalcula manifests.date como max(invoice_date) de sus facturas (corrige desfase histórico)';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $this->info($isDryRun
            ? '🔍 DRY-RUN: simulando cambios sin escribir a BD.'
            : '✏️  Aplicando cambios reales a BD.');

        $stats = [
            'examined' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'no_invoices' => 0,
            'errors' => 0,
        ];

        $samples = [];

        // chunkById sobre la tabla completa, ordenado por id.
        // Importante: NO usamos chunk() porque actualizar el mismo recorrido
        // que estamos paginando produce skip de filas (offset shift). chunkById
        // pagina por PK ascendente y es seguro bajo escrituras concurrentes.
        Manifest::query()
            ->select(['id', 'number', 'date'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($chunk) use (&$stats, &$samples, $isDryRun) {
                foreach ($chunk as $manifest) {
                    $stats['examined']++;

                    try {
                        // Una sola query agregada por manifiesto — no carga
                        // toda la colección de facturas en memoria.
                        $maxInvoiceDate = $manifest->invoices()->max('invoice_date');
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        $this->error("  ❌ Manifest #{$manifest->number}: {$e->getMessage()}");

                        continue;
                    }

                    if ($maxInvoiceDate === null) {
                        $stats['no_invoices']++;

                        continue;
                    }

                    $currentDate = $manifest->date?->toDateString();

                    if ($currentDate === $maxInvoiceDate) {
                        $stats['unchanged']++;

                        continue;
                    }

                    // Encontramos un manifest desfasado — registrar como sample
                    // (los primeros 10 para debug visible en consola) y aplicar.
                    if (count($samples) < 10) {
                        $samples[] = [
                            'manifest' => $manifest->number,
                            'antes' => $currentDate,
                            'despues' => $maxInvoiceDate,
                        ];
                    }

                    if (! $isDryRun) {
                        // updateQuietly no dispara eventos — evitamos cascadas
                        // de Observer/ActivityLog para un backfill masivo.
                        // La intención del backfill es corregir data, no
                        // generar 1000 entries de "manifest actualizado".
                        $manifest->updateQuietly(['date' => $maxInvoiceDate]);
                    }

                    $stats['updated']++;
                }
            });

        // ── Reporte final ──────────────────────────────────────────────
        $this->newLine();
        $this->info('📊 Resumen del backfill:');
        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Manifests examinados', $stats['examined']],
                [$isDryRun ? 'Habrían sido actualizados' : 'Actualizados', $stats['updated']],
                ['Sin cambios necesarios', $stats['unchanged']],
                ['Sin facturas (omitidos)', $stats['no_invoices']],
                ['Errores', $stats['errors']],
            ]
        );

        if (! empty($samples)) {
            $this->newLine();
            $this->info('🔬 Muestra de cambios (máx. 10):');
            $this->table(
                ['Manifest', 'Antes', 'Después'],
                $samples
            );
        }

        if ($isDryRun && $stats['updated'] > 0) {
            $this->newLine();
            $this->warn("⚠️  Para aplicar los cambios, corre de nuevo SIN --dry-run.");
        }

        return self::SUCCESS;
    }
}
