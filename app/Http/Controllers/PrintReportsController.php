<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ManifestWarehouseTotal;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PrintReportsController extends Controller
{
    /**
     * GET /imprimir/reportes/manifiestos?payload={encrypted}
     *
     * Payload:
     *   - date_from: string|null  (Y-m-d)
     *   - date_to:   string|null  (Y-m-d)
     *   - status:    string|null
     */
    public function manifests(Request $request): Response
    {
        $data = $this->decryptPayload($request);

        $query = Manifest::query()
            ->with(['warehouse'])
            ->orderBy('date', 'desc');

        if (! empty($data['date_from'])) {
            $query->whereDate('date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('date', '<=', $data['date_to']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $this->enforceRowLimit($query, 'manifiestos');
        $manifests = $query->get();

        $totals = [
            'total_invoices' => $manifests->sum('total_invoices'),
            'total_returns' => $manifests->sum('total_returns'),
            'total_to_deposit' => $manifests->sum('total_to_deposit'),
            'total_deposited' => $manifests->sum('total_deposited'),
            'difference' => $manifests->sum('difference'),
            'invoices_count' => $manifests->sum('invoices_count'),
            'clients_count' => $manifests->sum('clients_count'),
            'closed_count' => $manifests->where('status', 'closed')->count(),
            'open_count' => $manifests->whereNotIn('status', ['closed'])->count(),
        ];

        $reportNumber = 'MAN-'.now()->format('Ymd-His');

        $html = view('pdf.report-manifests', [
            'manifests' => $manifests,
            'totals' => $totals,
            'filters' => $data,
            'supplier' => Supplier::first(),
            'generatedAt' => now()->format('d/m/Y H:i:s'),
            'reportNumber' => $reportNumber,
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * GET /imprimir/reportes/manifiestos-sin-isv?payload={encrypted}
     *
     * Reporte de manifiestos con valores netos (sin ISV).
     *
     * Cálculo:
     *  - total_sin_isv      = SUM(invoices.total - invoices.isv15 - invoices.isv18)
     *  - isv_ratio          = (isv15 + isv18) / total_bruto  [proporción del manifiesto]
     *  - returns_sin_isv    = total_returns * (1 - isv_ratio)
     *  - depositar_sin_isv  = total_sin_isv - returns_sin_isv
     *
     * Se usa isv_ratio en vez de recalcular línea a línea porque las devoluciones
     * no almacenan ISV propio — heredan la proporción de la factura de origen.
     */
    public function manifestsSinIsv(Request $request): Response
    {
        $data = $this->decryptPayload($request);

        $query = Manifest::query()
            ->with(['warehouse'])
            ->orderBy('date', 'desc');

        if (! empty($data['date_from'])) {
            $query->whereDate('date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('date', '<=', $data['date_to']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $this->enforceRowLimit($query, 'manifiestos (sin ISV)');
        $manifests = $query->get();

        // Una sola query para obtener ISV por manifiesto (sin N+1).
        $manifestIds = $manifests->pluck('id')->toArray();

        $isvByManifest = Invoice::whereIn('manifest_id', $manifestIds)
            ->whereNotNull('warehouse_id')
            ->select(
                'manifest_id',
                DB::raw('COALESCE(SUM(isv15), 0) as total_isv15'),
                DB::raw('COALESCE(SUM(isv18), 0) as total_isv18'),
                DB::raw('COALESCE(SUM(total),  0) as total_bruto')
            )
            ->groupBy('manifest_id')
            ->get()
            ->keyBy('manifest_id');

        // Calcular valores sin ISV para cada manifiesto.
        $rows = $manifests->map(function (Manifest $m) use ($isvByManifest) {
            $isv = $isvByManifest->get($m->id);

            $isv15 = $isv ? (float) $isv->total_isv15 : 0.0;
            $isv18 = $isv ? (float) $isv->total_isv18 : 0.0;
            $totalIsv = $isv15 + $isv18;
            $totalBruto = $isv ? (float) $isv->total_bruto : 0.0;

            $totalSinIsv = $totalBruto - $totalIsv;

            // Proporción ISV del manifiesto para aplicar a devoluciones.
            $isvRatio = $totalBruto > 0 ? $totalIsv / $totalBruto : 0.0;
            $returnsSinIsv = (float) $m->total_returns * (1 - $isvRatio);
            $depositarSinIsv = $totalSinIsv - $returnsSinIsv;

            return [
                'manifest' => $m,
                'total_bruto' => round($totalBruto, 2),
                'total_isv15' => round($isv15, 2),
                'total_isv18' => round($isv18, 2),
                'total_isv' => round($totalIsv, 2),
                'total_sin_isv' => round($totalSinIsv, 2),
                'returns_sin_isv' => round($returnsSinIsv, 2),
                'depositar_sin_isv' => round($depositarSinIsv, 2),
                'isv_ratio' => $isvRatio,
                'clients_count' => (int) $m->clients_count,
            ];
        });

        $totals = [
            'total_bruto' => $rows->sum('total_bruto'),
            'total_isv15' => $rows->sum('total_isv15'),
            'total_isv18' => $rows->sum('total_isv18'),
            'total_isv' => $rows->sum('total_isv'),
            'total_sin_isv' => $rows->sum('total_sin_isv'),
            'returns_sin_isv' => $rows->sum('returns_sin_isv'),
            'depositar_sin_isv' => $rows->sum('depositar_sin_isv'),
            'invoices_count' => $manifests->sum('invoices_count'),
            'clients_count' => $manifests->sum('clients_count'),
            'closed_count' => $manifests->where('status', 'closed')->count(),
            'open_count' => $manifests->whereNotIn('status', ['closed'])->count(),
            'manifests_count' => $manifests->count(),
        ];

        // Número de correlativo único para trazabilidad / auditoría.
        $reportNumber = 'SINISV-'.now()->format('Ymd-His');

        $html = view('pdf.report-manifests-sin-isv', [
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $data,
            'supplier' => Supplier::first(),
            'generatedAt' => now()->format('d/m/Y H:i:s'),
            'reportNumber' => $reportNumber,
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * GET /imprimir/reportes/facturas?payload={encrypted}
     *
     * Payload:
     *   - manifest_id: int
     */
    public function invoices(Request $request): Response
    {
        $data = $this->decryptPayload($request);
        $manifest = Manifest::with(['warehouse', 'supplier'])->findOrFail((int) ($data['manifest_id'] ?? 0));

        $invoiceQuery = $manifest->invoices()
            ->with(['warehouse'])
            ->where('status', '!=', 'rejected');

        // Filtrar por bodega cuando el usuario tiene bodega asignada (operador)
        if (! empty($data['warehouse_id'])) {
            $invoiceQuery->where('warehouse_id', (int) $data['warehouse_id']);
        }

        $invoiceQuery
            ->orderBy('route_number')
            ->orderBy('invoice_number');

        $this->enforceRowLimit($invoiceQuery, 'facturas del manifiesto');
        $invoices = $invoiceQuery->get();

        // Agrupar por ruta con subtotales
        $byRoute = $invoices->groupBy('route_number')->map(function ($group) {
            return [
                'invoices' => $group,
                'subtotal' => $group->sum('total'),
                'count' => $group->count(),
            ];
        })->sortKeys();

        // Si se filtra por bodega, recalcular devoluciones y neto solo para esa bodega
        $warehouseFiltered = ! empty($data['warehouse_id']);
        $totalReturns = $warehouseFiltered
            ? $manifest->returns()
                ->where('warehouse_id', (int) $data['warehouse_id'])
                ->where('status', 'approved')
                ->sum('total')
            : $manifest->total_returns;

        $totalInvoices = $invoices->sum('total');

        $totals = [
            'total' => $totalInvoices,
            'count' => $invoices->count(),
            'total_isv15' => $invoices->sum('isv15'),
            'total_isv18' => $invoices->sum('isv18'),
            'total_returns' => $totalReturns,
            'net' => $totalInvoices - $totalReturns,
        ];

        $html = view('pdf.report-invoices', [
            'manifest' => $manifest,
            'byRoute' => $byRoute,
            'totals' => $totals,
            'supplier' => Supplier::first(),
            'generatedAt' => now()->format('d/m/Y H:i:s'),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * GET /imprimir/reportes/devoluciones?payload={encrypted}
     *
     * Payload:
     *   - manifest_id:  int|null   — filtra por manifiesto específico
     *   - date_from:    string|null
     *   - date_to:      string|null
     *   - status:       string|null
     *   - warehouse_id: int|null
     */
    public function returns(Request $request): Response
    {
        $data = $this->decryptPayload($request);

        // Cargar el manifiesto específico si viene del botón en ViewManifest
        $manifest = null;
        if (! empty($data['manifest_id'])) {
            $manifest = Manifest::with(['supplier', 'warehouse'])->find((int) $data['manifest_id']);
        }

        $query = InvoiceReturn::query()
            ->with(['invoice', 'manifest.supplier', 'warehouse', 'returnReason', 'createdBy', 'reviewedBy', 'lines'])
            ->orderBy('return_date', 'desc');

        // Filtro por manifiesto (usado desde ViewManifest)
        if (! empty($data['manifest_id'])) {
            $query->where('manifest_id', (int) $data['manifest_id']);
        }
        if (! empty($data['date_from'])) {
            $query->whereDate('return_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('return_date', '<=', $data['date_to']);
        }
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['warehouse_id'])) {
            $query->where('warehouse_id', $data['warehouse_id']);
        }

        $this->enforceRowLimit($query, 'devoluciones');
        $returns = $query->get();

        // Agrupar por manifiesto con subtotales
        $byManifest = $returns->groupBy('manifest.number')->map(function ($group) {
            return [
                'returns' => $group,
                'subtotal' => $group->sum('total'),
                'count' => $group->count(),
            ];
        })->sortKeys();

        $approved = $returns->where('status', 'approved');
        $pending = $returns->where('status', 'pending');

        $totals = [
            'total' => $returns->sum('total'),
            'count' => $returns->count(),
            'approved' => $approved->count(),
            'pending' => $pending->count(),
            'rejected' => $returns->where('status', 'rejected')->count(),
            'approved_amount' => $approved->sum('total'),
            'pending_amount' => $pending->sum('total'),
        ];

        $html = view('pdf.report-returns', [
            'byManifest' => $byManifest,
            'totals' => $totals,
            'filters' => $data,
            'manifest' => $manifest,
            'supplier' => Supplier::first(),
            'generatedAt' => now()->format('d/m/Y H:i:s'),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * GET /imprimir/reportes/depositos?payload={encrypted}
     *
     * Payload:
     *   - date_from: string|null
     *   - date_to:   string|null
     */
    public function deposits(Request $request): Response
    {
        $data = $this->decryptPayload($request);

        // active() excluye cancelados — un reporte de depósitos por banco
        // no debe sumar montos que ya no son operativos. Los cancelados
        // se auditan desde el tab "Cancelados" del listado.
        $query = Deposit::query()
            ->active()
            ->with(['manifest', 'createdBy'])
            ->orderBy('deposit_date', 'desc');

        if (! empty($data['date_from'])) {
            $query->whereDate('deposit_date', '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate('deposit_date', '<=', $data['date_to']);
        }

        $this->enforceRowLimit($query, 'depósitos');
        $deposits = $query->get();

        // Agrupar por banco con subtotales
        $byBank = $deposits->groupBy(fn ($d) => $d->bank ?? 'Sin banco')->map(function ($group) {
            return [
                'deposits' => $group,
                'subtotal' => $group->sum('amount'),
                'count' => $group->count(),
            ];
        })->sortKeys();

        $totals = [
            'total' => $deposits->sum('amount'),
            'count' => $deposits->count(),
        ];

        $html = view('pdf.report-deposits', [
            'byBank' => $byBank,
            'totals' => $totals,
            'filters' => $data,
            'supplier' => Supplier::first(),
            'generatedAt' => now()->format('d/m/Y H:i:s'),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * GET /imprimir/reportes/ventas-por-bodega?payload={encrypted}
     *
     * Reporte consolidado de ventas por bodega en un período.
     * Lee de manifest_warehouse_totals (datos ya pre-calculados) —
     * nunca va directo a invoices, lo que garantiza performance.
     *
     * Payload:
     *   - date_from:  string|null  (Y-m-d)
     *   - date_to:    string|null  (Y-m-d)
     *   - status:     string|null  ('imported'|'closed')
     *   - date_field: string       ('date'|'closed_at') — qué fecha del manifiesto se filtra
     */
    public function warehouseSales(Request $request): Response
    {
        $data = $this->decryptPayload($request);
        $dateField = in_array($data['date_field'] ?? 'date', ['date', 'closed_at']) ? $data['date_field'] : 'date';

        // ── Construir query sobre manifest_warehouse_totals ──────────────────
        // JOIN con manifests para filtrar por período y estado.
        // JOIN con warehouses para nombre/código.
        // Agrupamos por bodega y sumamos todos los manifiestos del período.
        $query = ManifestWarehouseTotal::query()
            ->join('manifests', 'manifest_warehouse_totals.manifest_id', '=', 'manifests.id')
            ->join('warehouses', 'manifest_warehouse_totals.warehouse_id', '=', 'warehouses.id')
            ->whereNull('manifests.deleted_at')
            ->select(
                'warehouses.id   as warehouse_id',
                'warehouses.code as warehouse_code',
                'warehouses.name as warehouse_name',
                'warehouses.city as warehouse_city',
                DB::raw('COUNT(DISTINCT manifests.id)                                    as manifests_count'),
                DB::raw('SUM(manifest_warehouse_totals.invoices_count)                   as invoices_count'),
                DB::raw('SUM(manifest_warehouse_totals.returns_count)                    as returns_count'),
                DB::raw('SUM(manifest_warehouse_totals.clients_count)                    as clients_count'),
                DB::raw('SUM(manifest_warehouse_totals.total_invoices)                   as total_invoices'),
                DB::raw('SUM(manifest_warehouse_totals.total_returns)                    as total_returns'),
                DB::raw('SUM(manifest_warehouse_totals.total_invoices)
                        - SUM(manifest_warehouse_totals.total_returns)                   as total_neto'),
            )
            ->groupBy('warehouses.id', 'warehouses.code', 'warehouses.name', 'warehouses.city')
            ->orderByDesc('total_neto');

        // Filtro por período
        if (! empty($data['date_from'])) {
            $query->whereDate("manifests.{$dateField}", '>=', $data['date_from']);
        }
        if (! empty($data['date_to'])) {
            $query->whereDate("manifests.{$dateField}", '<=', $data['date_to']);
        }
        // Filtro por estado del manifiesto
        if (! empty($data['status'])) {
            $query->where('manifests.status', $data['status']);
        }

        // Nota: este reporte agrupa por bodega (3 filas como máximo en prod)
        // así que el guard es más que nada defensivo ante configuraciones futuras.
        $this->enforceRowLimit($query, 'ventas por bodega');
        $rows = $query->get();

        // ── Totales globales ─────────────────────────────────────────────────
        $totals = [
            'manifests_count' => $rows->sum('manifests_count'),
            'invoices_count' => $rows->sum('invoices_count'),
            'returns_count' => $rows->sum('returns_count'),
            'clients_count' => $rows->sum('clients_count'),
            'total_invoices' => $rows->sum('total_invoices'),
            'total_returns' => $rows->sum('total_returns'),
            'total_neto' => $rows->sum('total_neto'),
            'warehouses_count' => $rows->count(),
        ];

        // ── Etiqueta legible del campo de fecha ──────────────────────────────
        $dateFieldLabel = $dateField === 'closed_at' ? 'Fecha de cierre' : 'Fecha del manifiesto';

        $reportNumber = 'BOD-'.now()->format('Ymd-His');

        $html = view('pdf.report-warehouse-sales', [
            'rows' => $rows,
            'totals' => $totals,
            'filters' => $data,
            'dateFieldLabel' => $dateFieldLabel,
            'supplier' => Supplier::first(),
            'generatedAt' => now()->format('d/m/Y H:i:s'),
            'reportNumber' => $reportNumber,
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * GET /imprimir/reportes/productos?payload={encrypted}
     *
     * Sublista de productos consolidada por manifiesto.
     * Agrupa todas las líneas de factura por product_id, sumando cajas y unidades.
     *
     * Payload:
     *   - manifest_id:  int
     *   - warehouse_id: int|null  (filtra facturas de una bodega específica)
     */
    public function products(Request $request): Response
    {
        $data = $this->decryptPayload($request);
        $manifest = Manifest::with(['warehouse', 'supplier'])->findOrFail((int) ($data['manifest_id'] ?? 0));

        // Query base: líneas de facturas no rechazadas del manifiesto
        $invoiceQuery = $manifest->invoices()
            ->where('status', '!=', 'rejected');

        // Filtrar por IDs específicos (bulk action) o por bodega
        if (! empty($data['invoice_ids'])) {
            $invoiceQuery->whereIn('id', $data['invoice_ids']);
        } elseif (! empty($data['warehouse_id'])) {
            $invoiceQuery->where('warehouse_id', (int) $data['warehouse_id']);
        }

        $invoiceIds = $invoiceQuery->pluck('id');

        // Agrupar líneas por producto, sumando cantidades y totales
        $productsQuery = DB::table('invoice_lines')
            ->whereIn('invoice_id', $invoiceIds)
            ->select(
                'product_id',
                'product_description',
                'unit_sale',
                DB::raw('SUM(quantity_box) as total_boxes'),
                DB::raw('SUM(quantity_fractions) as total_units'),
                DB::raw('SUM(total) as total_amount'),
            )
            ->groupBy('product_id', 'product_description', 'unit_sale')
            ->orderBy('product_id');

        $this->enforceRowLimit($productsQuery, 'productos del manifiesto');
        $products = $productsQuery->get();

        $totals = [
            'total_boxes' => $products->sum('total_boxes'),
            'total_units' => $products->sum('total_units'),
            'total_amount' => $products->sum('total_amount'),
            'count' => $products->count(),
        ];

        $html = view('pdf.report-products', [
            'manifest' => $manifest,
            'products' => $products,
            'totals' => $totals,
            'supplier' => Supplier::first(),
            'generatedAt' => now()->format('d/m/Y H:i:s'),
            'warehouseFiltered' => ! empty($data['warehouse_id']),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * GET /imprimir/reportes/facturas-checklist?payload={encrypted}
     *
     * Sublista de facturas simplificada para verificación en campo (operador).
     * Columnas: #, Factura, Cliente, Total, ✓
     * Agrupada por ruta. A4 portrait.
     *
     * Payload:
     *   - manifest_id:  int
     *   - warehouse_id: int|null  (filtra por bodega del operador)
     */
    public function invoicesChecklist(Request $request): Response
    {
        $data = $this->decryptPayload($request);
        $manifest = Manifest::with(['warehouse', 'supplier'])->findOrFail((int) ($data['manifest_id'] ?? 0));

        $invoiceQuery = $manifest->invoices()
            ->where('status', '!=', 'rejected');

        $warehouseFiltered = ! empty($data['warehouse_id']);
        $warehouseName = '—';

        // Filtrar por IDs específicos (bulk action) o por bodega
        if (! empty($data['invoice_ids'])) {
            $invoiceQuery->whereIn('id', $data['invoice_ids']);
        } elseif ($warehouseFiltered) {
            $invoiceQuery->where('warehouse_id', (int) $data['warehouse_id']);
            $warehouse = \App\Models\Warehouse::find((int) $data['warehouse_id']);
            $warehouseName = $warehouse ? "{$warehouse->code} — {$warehouse->name}" : '—';
        }

        $invoiceQuery
            ->orderBy('route_number')
            ->orderBy('invoice_number');

        $this->enforceRowLimit($invoiceQuery, 'checklist de facturas');
        $invoices = $invoiceQuery->get();

        // Agrupar por ruta con subtotales
        $byRoute = $invoices->groupBy('route_number')->map(function ($group) {
            return [
                'invoices' => $group->values(),
                'subtotal' => $group->sum('total'),
                'count' => $group->count(),
            ];
        })->sortKeys();

        $totals = [
            'total' => $invoices->sum('total'),
            'count' => $invoices->count(),
            'clients' => $invoices->pluck('client_id')->unique()->count(),
        ];

        $html = view('pdf.report-invoices-checklist', [
            'manifest' => $manifest,
            'byRoute' => $byRoute,
            'totals' => $totals,
            'supplier' => Supplier::first(),
            'generatedAt' => now()->format('d/m/Y H:i:s'),
            'warehouseFiltered' => $warehouseFiltered,
            'warehouseName' => $warehouseName,
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function decryptPayload(Request $request): array
    {
        try {
            $payload = Crypt::decryptString($request->query('payload', ''));

            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(403, 'Enlace de reporte inválido o expirado.');
        }
    }

    /**
     * Salvaguarda de memoria para reportes PDF.
     *
     * Antes de materializar la query con ->get(), contamos cuántas filas
     * devolvería y abortamos con 422 si supera REPORTS_MAX_ROWS. Esto
     * protege al proceso PHP (y al worker de Browsershot/Chromium) de
     * consumir toda la memoria cuando un usuario pide un rango demasiado
     * amplio — por ejemplo "todos los depósitos del año" en una instalación
     * con cientos de miles de filas.
     *
     * Se usa directamente en las queries ya construidas con filtros;
     * el count() reutiliza los mismos WHERE/JOIN pero sin SELECT pesado.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private function enforceRowLimit($query, string $reportName): void
    {
        $limit = (int) env('REPORTS_MAX_ROWS', 5000);
        if ($limit <= 0) {
            return; // 0 o negativo = sin límite (útil en dev)
        }

        $count = (clone $query)->count();

        if ($count > $limit) {
            abort(
                422,
                "El reporte de {$reportName} devolvería {$count} filas, superando el límite ".
                "de seguridad ({$limit}). Afine los filtros (rango de fechas, bodega, estado) ".
                'y vuelva a generarlo.'
            );
        }
    }
}
