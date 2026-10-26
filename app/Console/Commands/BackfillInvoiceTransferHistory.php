<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceWarehouseTransfer;
use App\Models\Manifest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/**
 * Rescata a invoice_warehouse_transfers los traslados de bodega que hasta hoy
 * solo vivían en activity_log.
 *
 * Por qué corre YA: activity_log se poda a los 90 días. El traslado de las
 * tres facturas de GAEL (OAI → OAS, 27/08/2026) se borraría a fines de
 * noviembre y el reporte de ventas ya no podría decir de dónde venían.
 *
 * Fuente: cada cambio de `invoices.warehouse_id` hecho con Eloquent deja un
 * "updated" con old/attributes; el motivo sale del evento
 * `warehouse_transfer` que InvoiceWarehouseTransferService escribe sobre el
 * manifiesto en el mismo momento.
 *
 * IDEMPOTENTE: un traslado que ya está en la tabla (misma factura, mismas
 * bodegas, ±10 minutos) no se vuelve a insertar, así que puede correrse
 * otra vez sin duplicar. Con --dry-run no escribe nada.
 */
class BackfillInvoiceTransferHistory extends Command
{
    protected $signature = 'invoices:backfill-transfer-history
                            {--dry-run : Solo mostrar lo que se rescataría, sin escribir}';

    protected $description = 'Rescata de activity_log el historial de traslados de facturas entre bodegas';

    private const MATCH_WINDOW_MINUTES = 10;

    private const FALLBACK_REASON = 'Traslado rescatado del historial (sin motivo registrado).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $changes = Activity::query()
            ->where('subject_type', Invoice::class)
            ->where('event', 'updated')
            ->whereRaw("(properties::jsonb -> 'old' ->> 'warehouse_id') IS NOT NULL")
            ->whereRaw("(properties::jsonb -> 'attributes' ->> 'warehouse_id') IS NOT NULL")
            ->orderBy('id')
            ->get();

        $warehouses = Warehouse::query()->pluck('code', 'id');
        $rows = [];
        $skipped = 0;

        foreach ($changes as $activity) {
            $from = (int) data_get($activity->properties, 'old.warehouse_id');
            $to = (int) data_get($activity->properties, 'attributes.warehouse_id');
            $at = Carbon::parse($activity->created_at);

            $invoice = Invoice::withTrashed()->find($activity->subject_id);

            if ($from === $to || $invoice === null || ! $warehouses->has($from) || ! $warehouses->has($to)) {
                $skipped++;

                continue;
            }

            if ($this->alreadyRecorded($invoice->id, $from, $to, $at)) {
                $skipped++;

                continue;
            }

            $event = $this->transferEventFor($invoice, $at);

            $rows[] = [
                'invoice' => $invoice,
                'from' => $from,
                'to' => $to,
                'at' => $at,
                // El traslado por consola no tiene sesión, así que el "updated"
                // de la factura queda sin causer; el responsable real es el
                // causer del evento del manifiesto (el --user del comando).
                'by' => $this->userIdOf($event) ?? $this->userIdOf($activity),
                'reason' => (string) data_get($event?->properties, 'motivo', self::FALLBACK_REASON),
            ];
        }

        $this->info('Cambios de bodega en activity_log: '.$changes->count()
            .' · por rescatar: '.count($rows).' · ya registrados u omitidos: '.$skipped);

        if ($rows !== []) {
            $this->table(
                ['Factura', 'De', 'A', 'Fecha', 'Usuario', 'Motivo'],
                array_map(fn (array $row): array => [
                    $row['invoice']->invoice_number,
                    $warehouses[$row['from']],
                    $warehouses[$row['to']],
                    $row['at']->format('d/m/Y H:i'),
                    $row['by'] ?? '—',
                    mb_strimwidth($row['reason'], 0, 60, '…'),
                ], $rows),
            );
        }

        if ($dryRun) {
            $this->warn('Dry-run: no se escribió nada.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            InvoiceWarehouseTransfer::query()->create([
                'invoice_id' => $row['invoice']->id,
                'from_warehouse_id' => $row['from'],
                'to_warehouse_id' => $row['to'],
                'transferred_by' => $row['by'],
                'reason' => $row['reason'],
                'transferred_at' => $row['at'],
            ]);
        }

        $this->info('Traslados rescatados: '.count($rows));

        return self::SUCCESS;
    }

    private function alreadyRecorded(int $invoiceId, int $from, int $to, Carbon $at): bool
    {
        return InvoiceWarehouseTransfer::query()
            ->where('invoice_id', $invoiceId)
            ->where('from_warehouse_id', $from)
            ->where('to_warehouse_id', $to)
            ->whereBetween('transferred_at', [
                $at->copy()->subMinutes(self::MATCH_WINDOW_MINUTES),
                $at->copy()->addMinutes(self::MATCH_WINDOW_MINUTES),
            ])
            ->exists();
    }

    /**
     * El motivo y el responsable viven en el evento `warehouse_transfer` del
     * manifiesto, escrito en la misma transacción que movió la factura.
     */
    private function transferEventFor(Invoice $invoice, Carbon $at): ?Activity
    {
        return Activity::query()
            ->where('subject_type', Manifest::class)
            ->where('subject_id', $invoice->manifest_id)
            ->where('event', 'warehouse_transfer')
            ->whereBetween('created_at', [
                $at->copy()->subMinutes(self::MATCH_WINDOW_MINUTES),
                $at->copy()->addMinutes(self::MATCH_WINDOW_MINUTES),
            ])
            ->get()
            ->first(fn (Activity $event): bool => in_array(
                $invoice->invoice_number,
                (array) data_get($event->properties, 'facturas', []),
                true,
            ));
    }

    private function userIdOf(?Activity $activity): ?int
    {
        if ($activity === null || $activity->causer_type !== User::class || $activity->causer_id === null) {
            return null;
        }

        return (int) $activity->causer_id;
    }
}
