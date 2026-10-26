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
     *     products_summary: list<array<string, mixed>>,
     *     products_totals: array<string, mixed>,
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
            ...$this->productsSummary($received, $sent),
        ];
    }

    /**
     * Resumen por producto de lo que llegó y salió por traslado, con totales.
     * Es la suma de las tablas de productos de cada factura.
     *
     * @param  list<array<string, mixed>>  $received
     * @param  list<array<string, mixed>>  $sent
     * @return array{products_summary: list<array<string, mixed>>, products_totals: array<string, mixed>}
     */
    private function productsSummary(array $received, array $sent): array
    {
        $rows = [];

        foreach (['received' => $received, 'sent' => $sent] as $direction => $invoices) {
            foreach ($invoices as $invoice) {
                foreach ($invoice['products'] as $product) {
                    $row = $rows[$product['product_id']] ?? [
                        'product_id' => $product['product_id'],
                        'description' => $product['description'],
                        'factor' => 0,
                        'received_units' => 0,
                        'received_amount' => 0.0,
                        'sent_units' => 0,
                        'sent_amount' => 0.0,
                    ];

                    $row['factor'] = max($row['factor'], $product['factor']);
                    $row["{$direction}_units"] += $product['units'];
                    $row["{$direction}_amount"] += $product['amount'];

                    $rows[$product['product_id']] = $row;
                }
            }
        }

        ksort($rows, SORT_STRING);

        $totals = [
            'received' => ['cajas' => 0, 'sueltas' => 0],
            'sent' => ['cajas' => 0, 'sueltas' => 0],
            'received_amount' => 0.0,
            'sent_amount' => 0.0,
        ];

        $summary = [];

        foreach ($rows as $row) {
            foreach (['received', 'sent'] as $direction) {
                $boxes = ProductSalesReportService::boxes($row["{$direction}_units"], $row['factor']);
                $row[$direction] = $boxes;
                $totals[$direction]['cajas'] += $boxes['cajas'];
                $totals[$direction]['sueltas'] += $boxes['sueltas'];
                $row["{$direction}_amount"] = round($row["{$direction}_amount"], 2);
                $totals["{$direction}_amount"] += $row["{$direction}_amount"];
            }

            $summary[] = $row;
        }

        foreach (['received', 'sent'] as $direction) {
            $totals[$direction]['label'] = ProductSalesReportService::boxesLabel(
                $totals[$direction]['cajas'],
                $totals[$direction]['sueltas'],
            );
            $totals["{$direction}_amount"] = round($totals["{$direction}_amount"], 2);
        }

        return ['products_summary' => $summary, 'products_totals' => $totals];
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

        // Productos de cada factura, consolidados por código (CJ y UN juntos)
        // con la misma regla de unidades que la tabla principal.
        $products = DB::table('invoice_lines as il')
            ->whereIn('il.invoice_id', $invoiceIds)
            ->select([
                'il.invoice_id',
                'il.product_id',
                DB::raw('MIN(il.product_description) as product_description'),
                DB::raw('MAX(il.conversion_factor) as conversion_factor'),
                DB::raw('SUM('.ProductSalesReportService::LINE_UNITS_SQL.') as units'),
                DB::raw('SUM(il.total) as amount'),
            ])
            ->groupBy('il.invoice_id', 'il.product_id')
            ->orderBy('il.product_id')
            ->get()
            ->groupBy('invoice_id');

        $details = [];

        foreach ($invoices as $invoice) {
            $details[$invoice->id] = $this->invoiceDetail(
                $invoice,
                $moves->get($invoice->id, collect()),
                $returns->get($invoice->id, collect()),
                $products->get($invoice->id, collect()),
            );
        }

        return $details;
    }

    /**
     * @param  Collection<int, InvoiceWarehouseTransfer>  $moves
     * @param  Collection<int, InvoiceReturn>  $returns
     * @param  Collection<int, object>  $products
     * @return array<string, mixed>
     */
    private function invoiceDetail(Invoice $invoice, Collection $moves, Collection $returns, Collection $products): array
    {
        $origin = $moves->first()?->fromWarehouse?->code ?? 'Sin bodega';
        $chain = collect([$origin])
            ->merge($moves->map(fn (InvoiceWarehouseTransfer $move): string => $move->toWarehouse?->code ?? '?'))
            ->implode(' → ');

        $returnDetails = $returns
            ->map(fn (InvoiceReturn $return): array => $this->returnDetail($return, $moves, $origin))
            ->values()
            ->all();

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
            'products' => $this->productRows($products, $returnDetails),
            'returns' => $returnDetails,
        ];
    }

    /**
     * Productos de la factura con lo devuelto de cada uno y quién lo devolvió
     * (usuario, sus bodegas, en qué bodega estaba la factura y si fue antes o
     * después del traslado). Solo cuentan las devoluciones aprobadas o
     * pendientes; las canceladas siguen visibles en la tabla de devoluciones.
     *
     * @param  Collection<int, object>  $products
     * @param  list<array<string, mixed>>  $returnDetails
     * @return list<array<string, mixed>>
     */
    private function productRows(Collection $products, array $returnDetails): array
    {
        $returnedByProduct = [];

        foreach ($returnDetails as $return) {
            if (! $return['counts']) {
                continue;
            }

            foreach ($return['lines'] as $line) {
                $entry = $returnedByProduct[$line['product_id']] ?? ['units' => 0, 'by' => []];
                $entry['units'] += $line['units'];
                $entry['by'][] = [
                    'user' => $return['user'],
                    'user_warehouses' => $return['user_warehouses'],
                    'registered_in' => $return['registered_in'],
                    'timing' => $return['timing'],
                    'registered_at' => $return['registered_at'],
                    'quantity' => $line['quantity'],
                ];
                $returnedByProduct[$line['product_id']] = $entry;
            }
        }

        return $products->map(function (object $product) use ($returnedByProduct): array {
            $units = (int) round((float) $product->units);
            $factor = (int) ($product->conversion_factor ?? 0);
            $amount = round((float) $product->amount, 2);
            $returned = $returnedByProduct[(string) $product->product_id] ?? ['units' => 0, 'by' => []];

            return [
                'product_id' => (string) $product->product_id,
                'description' => (string) $product->product_description,
                'units' => $units,
                'factor' => $factor,
                'quantity' => ProductSalesReportService::boxes($units, $factor)['label'],
                'returned_units' => $returned['units'],
                'returned' => ProductSalesReportService::boxes($returned['units'], $factor)['label'],
                'returned_by' => $returned['by'],
                'net' => ProductSalesReportService::boxes(max(0, $units - $returned['units']), $factor)['label'],
                'amount' => $amount,
                'bonus' => $amount == 0.0 && $units > 0,
            ];
        })->values()->all();
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
            'lines' => $return->lines->map(function (ReturnLine $line): array {
                $factor = (int) ($line->invoiceLine?->conversion_factor ?? 0);
                $units = (int) round((float) $line->quantity_box * max(1, $factor) + (float) $line->quantity);

                return [
                    'product_id' => (string) $line->product_id,
                    'description' => (string) $line->product_description,
                    'units' => $units,
                    'quantity' => ProductSalesReportService::boxes($units, $factor)['label'],
                    'amount' => round((float) $line->line_total, 2),
                ];
            })->values()->all(),
        ];
    }
}
