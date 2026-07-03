<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ManifestWarehouseTotal;
use App\Models\Supplier;
use App\Support\BoxEquivalence;
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

        // Filtrar por bodega(s) cuando el usuario tiene bodegas asignadas (operador/encargado)
        $warehouseIds = $this->warehouseIdsFromPayload($data);
        if ($warehouseIds !== []) {
            $invoiceQuery->whereIn('warehouse_id', $warehouseIds);
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

        // Si se filtra por bodega(s), recalcular devoluciones y neto solo para esas bodegas
        $warehouseFiltered = $warehouseIds !== [];
        $totalReturns = $warehouseFiltered
            ? $manifest->returns()
                ->whereIn('warehouse_id', $warehouseIds)
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
        $warehouseIds = $this->warehouseIdsFromPayload($data);
        if ($warehouseIds !== []) {
            $query->whereIn('warehouse_id', $warehouseIds);
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
        // Visibilidad por jerarquía: el reporte refleja solo los depósitos que
        // el usuario puede ver (él + su subárbol created_by); super_admin todos.
        // Antes no filtraba nada → exponía depósitos de todas las bodegas.
        $query = Deposit::query()
            ->active()
            ->visibleTo($request->user())
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

        // Filtrar por IDs específicos (bulk action) o por bodega(s)
        $warehouseIds = $this->warehouseIdsFromPayload($data);
        if (! empty($data['invoice_ids'])) {
            $invoiceQuery->whereIn('id', $data['invoice_ids']);
        } elseif ($warehouseIds !== []) {
            $invoiceQuery->whereIn('warehouse_id', $warehouseIds);
        }

        $invoiceIds = $invoiceQuery->pluck('id');

        // Transparencia anti-confusión: si el payload recorta el universo de
        // facturas (selección parcial en la tabla, o filtro por bodega), el
        // reporte DEBE declararlo. Un subconjunto silencioso ya causó que la
        // bodega creyera que el sistema "perdía productos" (2026-07-01).
        $totalManifestInvoices = $manifest->invoices()
            ->where('status', '!=', 'rejected')
            ->count();
        $includedInvoices = $invoiceIds->count();

        // Agrupar líneas por PRODUCTO (una sola fila por producto), sumando
        // cantidades y totales. NO se agrupa por unit_sale: un producto vendido
        // en caja (CJ) y en unidad (UN) a la vez debe salir en UNA fila con sus
        // cajas + unidades juntas (antes salía partido en dos filas, lo que
        // confundía a la bodega). UDC muestra TODAS las presentaciones en que
        // vino el producto, unidas con "/" (ej. "CJ/UN") — así el bodeguero
        // sabe que la fila consolida cajas y sueltas de facturas distintas.
        $productsQuery = DB::table('invoice_lines')
            ->whereIn('invoice_id', $invoiceIds)
            ->select(
                'product_id',
                DB::raw('MIN(product_description) as product_description'),
                DB::raw("STRING_AGG(DISTINCT unit_sale, '/' ORDER BY unit_sale) as unit_sale"),
                DB::raw('SUM(quantity_box) as total_boxes'),
                // Total REAL de unidades por línea. quantity_fractions tiene dos
                // semánticas históricas según cómo entró la línea:
                //   a) Normalizada (import API, caso CJ puro): fractions YA
                //      incluye las cajas (cajas × factor).
                //   b) Cruda (línea mixta de Jaremar con CantidadCaja>0 Y
                //      CantidadFracciones>0, o import manual sin normalizar):
                //      fractions trae SOLO las sueltas y las cajas van aparte
                //      en quantity_box.
                // La distinción es matemática, no heurística: si fractions <
                // cajas × factor, es IMPOSIBLE que las cajas estén incluidas
                // (una caja completa nunca suma menos que sí misma) → se
                // agregan. Así ningún formato de línea pierde mercadería.
                DB::raw('SUM(CASE
                    WHEN quantity_fractions < quantity_box * conversion_factor
                    THEN quantity_box * conversion_factor + quantity_fractions
                    ELSE quantity_fractions
                END) as total_units'),
                DB::raw('SUM(total) as total_amount'),
                // Unidades por caja del producto. MAX (no AVG) porque alguna
                // línea suelta podría traer factor 1 por error de origen;
                // así tomamos el factor real para convertir unidades→cajas.
                DB::raw('MAX(conversion_factor) as conversion_factor'),
            )
            ->groupBy('product_id')
            ->orderBy('product_id');

        $this->enforceRowLimit($productsQuery, 'productos del manifiesto');
        $products = $productsQuery->get();

        // Totales coherentes con la descomposición de las filas. Como cada fila
        // ya es UN producto consolidado, descomponemos la suma total de fracciones
        // por el factor: quantity_fractions trae el total en unidades (caja×factor
        // + sueltas), así cajas = cajas reales + equivalentes y sueltas = sobrante.
        //   - total_boxes (pie) = cajas reales (CJ/FD) + cajas equivalentes (UN)
        //   - total_units (pie) = solo las unidades sueltas sobrantes
        $totalBoxes = 0;
        $totalLoose = 0;
        foreach ($products as $product) {
            $eq = BoxEquivalence::split(
                (int) round((float) $product->total_units),
                (int) ($product->conversion_factor ?? 0),
            );
            $totalBoxes += $eq['cajas'];
            $totalLoose += $eq['sueltas'];
        }

        // TOTAL GENERAL = suma de los TOTALES DE FACTURA (valor fiscal real), NO
        // la suma de las líneas. El total de cada factura de Jaremar no siempre
        // cuadra exacto con la suma de sus líneas (redondeo del proveedor); el
        // valor real —el que se deposita y con el que se concilia— es el de la
        // factura. Así la Sublista, el checklist de facturas y el Total Manifiesto
        // dan EXACTAMENTE lo mismo.
        $totals = [
            'total_boxes' => $totalBoxes,
            'total_units' => $totalLoose,
            'total_amount' => (float) DB::table('invoices')->whereIn('id', $invoiceIds)->sum('total'),
            'count' => $products->count(),
        ];

        $html = view('pdf.report-products', [
            'manifest' => $manifest,
            'products' => $products,
            'totals' => $totals,
            'supplier' => Supplier::first(),
            'generatedAt' => now()->format('d/m/Y H:i:s'),
            'warehouseFiltered' => $warehouseIds !== [],
            'includedInvoices' => $includedInvoices,
            'totalManifestInvoices' => $totalManifestInvoices,
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

        $warehouseIds = $this->warehouseIdsFromPayload($data);
        $warehouseFiltered = $warehouseIds !== [];
        $warehouseName = '—';

        // Filtrar por IDs específicos (bulk action) o por bodega(s)
        if (! empty($data['invoice_ids'])) {
            $invoiceQuery->whereIn('id', $data['invoice_ids']);
        } elseif ($warehouseFiltered) {
            $invoiceQuery->whereIn('warehouse_id', $warehouseIds);
            $warehouses = \App\Models\Warehouse::whereIn('id', $warehouseIds)->get();
            $warehouseName = $warehouses
                ->map(fn ($w) => "{$w->code} — {$w->name}")
                ->implode(', ') ?: '—';
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

    /**
     * Normaliza los IDs de bodega del payload.
     *
     * Soporta el formato multi-bodega `warehouse_ids` (array) y el legacy
     * `warehouse_id` (int) por retrocompatibilidad con enlaces viejos.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int> Vacío = sin filtro de bodega (ver todo).
     */
    private function warehouseIdsFromPayload(array $data): array
    {
        if (! empty($data['warehouse_ids']) && is_array($data['warehouse_ids'])) {
            return array_values(array_map('intval', $data['warehouse_ids']));
        }

        if (! empty($data['warehouse_id'])) {
            return [(int) $data['warehouse_id']];
        }

        return [];
    }

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
        $limit = (int) config('reports.max_rows');
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
