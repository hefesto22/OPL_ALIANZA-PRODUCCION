<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\InvoiceWarehouseTransfer;
use App\Models\ReturnLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sección "facturas trasladadas" del reporte de ventas por producto.
 *
 * Una factura trasladada cuenta como venta de la bodega donde QUEDÓ (igual que
 * A Depositar); esta sección explica el movimiento (decidido con Mauricio,
 * 2026-10-25):
 *
 *   - Recibidas: hoy están en las bodegas del reporte y llegaron por traslado.
 *   - Enviadas: salieron de alguna bodega del reporte y hoy están en otra; NO
 *     suman en este reporte, se listan para que se sepa a dónde se fueron.
 *
 * Por cada factura: la cadena de bodegas (OAI → OAS), cuándo, quién y por qué
 * se trasladó, y cada devolución con el usuario que la registró, sus bodegas,
 * en qué bodega estaba la factura en ese momento y si fue antes o después
 * del traslado.
 *
 * Fuente: invoice_warehouse_transfers (historial durable; activity_log se poda
 * a los 90 días). Los traslados son excepcionales, así que son pocas facturas:
 * se cargan con eager loading en cuatro consultas fijas, sin N+1.
 */
final class ProductSalesTransfersSection
{
    private const RETURN_STATUS_LABELS = [
        'approved' => 'Aprobada',
        'pending' => 'Pendiente',
        'cancelled' => 'Cancelada',
        'rejected' => 'Rechazada',
    ];

    private const COUNTED_RETURN_STATUSES = ['approved', 'pending'];

    /**
     * @return array{
     *     received: list<array<string, mixed>>,
     *     sent: list<array<string, mixed>>,
     *     received_count: int,
     *     received_total: float,
     *     sent_count: int,
     *     sent_total: float,
     * }
     */
    public function build(ProductSalesFilters $filters): array
    {
        $receivedIds = $filters->scopeInvoices(DB::table('invoices as i')->select('i.id'))
            ->whereIn('i.id', DB::table('invoice_warehouse_transfers')->select('invoice_id'))
            ->pluck('i.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $sentIds = $filters->scopeInvoices(DB::table('invoices as i')->select('i.id'), onlySelectedWarehouses: false)
            ->whereNotIn('i.warehouse_id', $filters->warehouseIds)
            ->whereIn('i.id', DB::table('invoice_warehouse_transfers')
                ->select('invoice_id')
                ->whereIn('from_warehouse_id', $filters->warehouseIds))
            ->pluck('i.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $details = $this->details(array_merge($receivedIds, $sentIds));

        $received = array_values(array_intersect_key($details, array_flip($receivedIds)));
        $sent = array_values(array_intersect_key($details, array_flip($sentIds)));

        return [
            'received' => $received,
            'sent' => $sent,
            'received_count' => count($received),
            'received_total' => round(array_sum(array_column($received, 'total')), 2),
            'sent_count' => count($sent),
            'sent_total' => round(array_sum(array_column($sent, 'total')), 2),
        ];
    }

    /**
     * @param  list<int>  $invoiceIds
     * @return array<int, array<string, mixed>> Por id de factura, ordenadas por fecha y número.
     */
    private function details(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $invoices = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->with(['manifest:id,number', 'warehouse:id,code'])
            ->orderBy('invoice_date')
            ->orderBy('invoice_number')
            ->get(['id', 'invoice_number', 'invoice_date', 'client_id', 'client_name', 'manifest_id', 'warehouse_id', 'total', 'total_returns']);

        $moves = InvoiceWarehouseTransfer::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->with(['fromWarehouse:id,code', 'toWarehouse:id,code', 'transferredBy:id,name'])
            ->orderBy('transferred_at')
            ->orderBy('id')
            ->get()
            ->groupBy('invoice_id');

        $returns = InvoiceReturn::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->with(['createdBy:id,name', 'createdBy.warehouses', 'lines.invoiceLine:id,conversion_factor'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->groupBy('invoice_id');

        $details = [];

        foreach ($invoices as $invoice) {
            $details[$invoice->id] = $this->invoiceDetail(
                $invoice,
                $moves->get($invoice->id, collect()),
                $returns->get($invoice->id, collect()),
            );
        }

        return $details;
    }

    /**
     * @param  Collection<int, InvoiceWarehouseTransfer>  $moves
     * @param  Collection<int, InvoiceReturn>  $returns
     * @return array<string, mixed>
     */
    private function invoiceDetail(Invoice $invoice, Collection $moves, Collection $returns): array
    {
        $origin = $moves->first()?->fromWarehouse?->code ?? 'Sin bodega';
        $chain = collect([$origin])
            ->merge($moves->map(fn (InvoiceWarehouseTransfer $move): string => $move->toWarehouse?->code ?? '?'))
            ->implode(' → ');

        return [
            'id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'invoice_date' => $invoice->invoice_date?->format('d/m/Y') ?? '—',
            'client' => trim(($invoice->client_id ? $invoice->client_id.' · ' : '').$invoice->client_name),
            'manifest_number' => (string) ($invoice->manifest?->number ?? '—'),
            'current_warehouse' => (string) ($invoice->warehouse?->code ?? '—'),
            'chain' => $chain,
            'total' => round((float) $invoice->total, 2),
            'returned_total' => round((float) $invoice->total_returns, 2),
            'moves' => $moves->map(fn (InvoiceWarehouseTransfer $move): array => [
                'from' => $move->fromWarehouse?->code ?? 'Sin bodega',
                'to' => $move->toWarehouse?->code ?? '?',
                'at' => $move->transferred_at->format('d/m/Y H:i'),
                'by' => $move->transferredBy?->name ?? 'Sin usuario',
                'reason' => (string) $move->reason,
            ])->values()->all(),
            'returns' => $returns->map(fn (InvoiceReturn $return): array => $this->returnDetail($return, $moves, $origin))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, InvoiceWarehouseTransfer>  $moves
     * @return array<string, mixed>
     */
    private function returnDetail(InvoiceReturn $return, Collection $moves, string $origin): array
    {
        $registeredAt = $return->created_at;

        // En qué bodega estaba la factura cuando se registró la devolución:
        // la del último traslado anterior a ese momento, o la de origen.
        $lastMoveBefore = $moves->filter(
            fn (InvoiceWarehouseTransfer $move): bool => $registeredAt !== null && $move->transferred_at->lte($registeredAt)
        )->last();

        $timing = match (true) {
            $lastMoveBefore === null => 'Antes del traslado',
            $moves->count() > 1 => 'Después del traslado del '.$lastMoveBefore->transferred_at->format('d/m/Y'),
            default => 'Después del traslado',
        };

        $userWarehouses = $return->createdBy?->warehouses->pluck('code')->sort()->implode(', ');

        return [
            'registered_at' => $registeredAt?->format('d/m/Y H:i') ?? '—',
            'return_date' => $return->return_date?->format('d/m/Y') ?? '—',
            'user' => $return->createdBy?->name ?? 'Sin usuario',
            'user_warehouses' => $return->createdBy === null ? '—' : ($userWarehouses !== '' ? $userWarehouses : 'Todas (global)'),
            'registered_in' => $lastMoveBefore?->toWarehouse?->code ?? $origin,
            'timing' => $timing,
            'type' => $return->type === 'total' ? 'Total' : 'Parcial',
            'status' => self::RETURN_STATUS_LABELS[$return->status] ?? (string) $return->status,
            'counts' => in_array($return->status, self::COUNTED_RETURN_STATUSES, true),
            'total' => round((float) $return->total, 2),
            'lines' => $return->lines->map(fn (ReturnLine $line): array => [
                'product_id' => (string) $line->product_id,
                'description' => (string) $line->product_description,
                'quantity' => ProductSalesReportService::boxes(
                    (int) round((float) $line->quantity_box * max(1, (int) ($line->invoiceLine?->conversion_factor ?? 1)) + (float) $line->quantity),
                    (int) ($line->invoiceLine?->conversion_factor ?? 0),
                )['label'],
                'amount' => round((float) $line->line_total, 2),
            ])->values()->all(),
        ];
    }
}
