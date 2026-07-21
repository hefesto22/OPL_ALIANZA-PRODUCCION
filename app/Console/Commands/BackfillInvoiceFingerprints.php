<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Support\InvoiceFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill de la huella de duplicado exacto para facturas históricas.
 *
 * Idempotente: solo procesa facturas con fingerprint NULL, así que puede
 * correrse las veces que sea (y re-correrse tras un deploy interrumpido).
 * Si la fórmula canónica de InvoiceFingerprint cambia, hay que limpiar la
 * columna primero (UPDATE invoices SET fingerprint = NULL) y re-correr.
 *
 * Uso:
 *   php artisan invoices:backfill-fingerprints            # aplica
 *   php artisan invoices:backfill-fingerprints --dry-run  # solo cuenta
 */
class BackfillInvoiceFingerprints extends Command
{
    protected $signature = 'invoices:backfill-fingerprints
                            {--chunk=500 : Facturas por chunk}
                            {--dry-run : Contar sin escribir}';

    protected $description = 'Calcula la huella de duplicado (fingerprint) para facturas que aún no la tienen';

    public function handle(): int
    {
        $chunk = max(50, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $pending = Invoice::whereNull('fingerprint')->count();
        $this->info("Facturas sin fingerprint: {$pending}".($dryRun ? ' (dry-run, no se escribirá nada)' : ''));

        if ($pending === 0) {
            return self::SUCCESS;
        }

        $filled = 0;
        $skipped = 0;

        Invoice::whereNull('fingerprint')
            ->with('lines:id,invoice_id,product_id,quantity_fractions')
            ->chunkById($chunk, function ($invoices) use (&$filled, &$skipped, $dryRun) {
                foreach ($invoices as $invoice) {
                    $fingerprint = InvoiceFingerprint::fromInvoice($invoice);

                    // Sin client_id o sin líneas no hay huella confiable: la
                    // factura queda fuera de la detección (fingerprint NULL).
                    if ($fingerprint === null) {
                        $skipped++;

                        continue;
                    }

                    if (! $dryRun) {
                        // Update directo sin Eloquent: no dispara eventos ni
                        // ActivityLog (es un cálculo derivado, no un cambio
                        // de negocio) y no toca updated_at.
                        DB::table('invoices')
                            ->where('id', $invoice->id)
                            ->update(['fingerprint' => $fingerprint]);
                    }

                    $filled++;
                }
            });

        $verbo = $dryRun ? 'calculables' : 'actualizadas';
        $this->info("Listo: {$filled} factura(s) {$verbo}, {$skipped} sin huella posible (sin cliente o sin líneas).");

        return self::SUCCESS;
    }
}
