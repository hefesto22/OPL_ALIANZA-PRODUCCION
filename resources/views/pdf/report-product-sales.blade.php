@php
    $totals = $report['totals'];
    $from = \Illuminate\Support\Carbon::parse($report['filters']['date_from'])->format('d/m/Y');
    $to = \Illuminate\Support\Carbon::parse($report['filters']['date_to'])->format('d/m/Y');
    $warehouseLabel = collect($report['warehouses'])
        ->map(fn (array $warehouse): string => $warehouse['code'].' — '.$warehouse['name'])
        ->implode(' · ');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<title>Reporte de Ventas por Producto — {{ $from }} al {{ $to }}</title>
<style>
/* ── Toolbar (solo pantalla) ─────────────────────────────────── */
#toolbar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
    background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%);
    color: #fff; padding: 10px 20px;
    display: flex; align-items: center; justify-content: space-between;
    font-family: Arial, sans-serif; font-size: 13px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.3);
}
#toolbar .info strong { font-size: 15px; display: block; }
#toolbar .info span { font-size: 12px; color: #aac4e8; }
#toolbar .btn-print {
    background: #f59e0b; color: #000; border: none;
    padding: 8px 22px; border-radius: 6px;
    font-size: 14px; font-weight: bold; cursor: pointer;
}
#toolbar .btn-print:hover { background: #d97706; }

/* ── Base ─────────────────────────────────────────────────────── */
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 8pt; color: #1a1a1a; background: #e5e7eb; margin: 0; }
#report-container { margin-top: 60px; padding: 16px; }
.report-page {
    background: #fff; width: 277mm; margin: 0 auto 16px auto; padding: 8mm;
    box-shadow: 0 2px 12px rgba(0,0,0,0.12); box-sizing: border-box;
}

/* ── Header ───────────────────────────────────────────────────── */
.report-header { border-bottom: 3px solid #1e3a5f; padding-bottom: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: flex-start; }
.report-header .company { font-size: 13pt; font-weight: bold; color: #1e3a5f; }
.report-header .title { font-size: 11pt; font-weight: bold; margin: 3px 0 2px; color: #333; }
.report-header .meta { font-size: 7.5pt; color: #555; line-height: 1.6; }
.report-header .right { text-align: right; padding-left: 16px; font-size: 7.5pt; color: #555; line-height: 1.6; }
.report-num { display: inline-block; background: #1e3a5f; color: #fff; padding: 5px 12px; border-radius: 6px; font-size: 10pt; font-weight: bold; letter-spacing: .5px; margin-bottom: 4px; }

/* ── Banner ventana abierta ───────────────────────────────────── */
.window-banner { background: #fef3c7; border: 2px solid #f59e0b; border-radius: 6px; padding: 6px 10px; margin-bottom: 10px; font-size: 8pt; color: #92400e; }
.window-banner strong { text-transform: uppercase; }

/* ── Summary cards ────────────────────────────────────────────── */
.summary-box { display: flex; gap: 8px; margin-bottom: 10px; }
.summary-card { flex: 1; border: 1px solid #e2e8f0; border-top: 3px solid #1e3a5f; border-radius: 6px; padding: 7px 10px; background: #f8fafc; text-align: center; }
.summary-card .label { font-size: 6.5pt; color: #64748b; text-transform: uppercase; letter-spacing: .5px; }
.summary-card .value { font-size: 11pt; font-weight: bold; color: #1e3a5f; margin-top: 2px; }
.summary-card .sub { font-size: 6.5pt; color: #64748b; margin-top: 1px; }
.summary-card.returned { border-top-color: #dc2626; }
.summary-card.returned .value { color: #b91c1c; }
.summary-card.net { border-top-color: #16a34a; }
.summary-card.net .value { color: #15803d; }

/* ── Table ────────────────────────────────────────────────────── */
table.data { width: 100%; border-collapse: collapse; font-size: 7pt; table-layout: fixed; }
table.data col.c-num { width: 18px; }
table.data col.c-code { width: 52px; }
table.data col.c-desc { }
table.data col.c-udc { width: 34px; }
table.data col.c-qty { width: 66px; }
table.data col.c-pct { width: 42px; }
table.data col.c-amt { width: 66px; }
table.data thead tr { background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%); color: #fff; }
table.data thead th { padding: 4px 3px; text-align: right; font-size: 6pt; text-transform: uppercase; white-space: nowrap; }
table.data thead th.l { text-align: left; }
table.data thead th.c { text-align: center; }
table.data tbody tr:nth-child(even) { background: #f8fafc; }
table.data tbody td { padding: 3px; border-bottom: 1px solid #e5e7eb; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
table.data tbody td.l { text-align: left; white-space: normal; }
table.data tbody td.c { text-align: center; }
table.data tbody td.code { font-family: 'Consolas', 'Courier New', monospace; color: #475569; text-align: left; }
table.data tbody td.row-num { color: #94a3b8; font-size: 6pt; text-align: center; }
table.data td.bonus { color: #15803d; }
table.data td.ret { color: #b91c1c; }
table.data td.net { font-weight: bold; }
table.data td.zero { color: #cbd5e1; }
table.data .sep { border-left: 1px solid #cbd5e1; }
table.data tfoot tr { background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%); color: #fff; font-weight: bold; }
table.data tfoot td { padding: 5px 3px; font-size: 7pt; text-align: right; white-space: nowrap; }
table.data tfoot td.l { text-align: left; }

.notes { margin-top: 10px; font-size: 7pt; color: #475569; line-height: 1.55; border: 1px solid #e2e8f0; border-radius: 6px; padding: 7px 10px; background: #f8fafc; }
.notes strong { color: #1e3a5f; }
.report-footer { margin-top: 12px; border-top: 2px solid #e2e8f0; padding-top: 6px; font-size: 7pt; color: #94a3b8; text-align: center; }

/* ── Paginación ───────────────────────────────────────────────── */
table.data thead { display: table-header-group; }
table.data tfoot { display: table-row-group; }
table.data tbody tr { page-break-inside: avoid; break-inside: avoid; }

/* ── Print ────────────────────────────────────────────────────── */
@media print {
    @page { size: A4 landscape; margin: 8mm; }
    #toolbar { display: none !important; }
    body { background: none; }
    #report-container { margin-top: 0; padding: 0; }
    .report-page { width: 100%; margin: 0; padding: 0; box-shadow: none; }
}
</style>
</head>
<body>

<div id="toolbar">
    <div class="info">
        <strong>Reporte de Ventas por Producto — {{ $from }} al {{ $to }}</strong>
        <span>{{ number_format($totals['products_count']) }} productos · {{ number_format($totals['invoices_count']) }} facturas · Generado: {{ $report['generated_at'] }}</span>
    </div>
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
</div>

<div id="report-container">
<div class="report-page">

    <div class="report-header">
        <div>
            <div class="company">{{ $supplier?->name ?? 'Distribuidora Hosana' }}</div>
            <div class="title">Reporte de Ventas por Producto</div>
            <div class="meta">
                Período: <strong>{{ $from }} al {{ $to }}</strong> &nbsp;·&nbsp; Contado por: <strong>{{ $report['date_field_label'] }}</strong><br>
                Bodegas: <strong>{{ $warehouseLabel !== '' ? $warehouseLabel : '—' }}</strong><br>
                Orden: {{ $sortLabel }}
            </div>
        </div>
        <div class="right">
            <div class="report-num">{{ $reportNumber }}</div><br>
            Generado: {{ $report['generated_at'] }}<br>
            Facturas: <strong>{{ number_format($totals['invoices_count']) }}</strong> &nbsp;·&nbsp; Productos: <strong>{{ number_format($totals['products_count']) }}</strong>
        </div>
    </div>

    @if ($report['open_return_windows'] > 0)
    <div class="window-banner">
        &#9888;&#65039; <strong>Período abierto:</strong>
        {{ $report['open_return_windows'] }} {{ $report['open_return_windows'] === 1 ? 'manifiesto sigue' : 'manifiestos siguen' }}
        con la ventana de devoluciones abierta. Lo devuelto puede aumentar; vuelva a generar el reporte cuando cierren.
    </div>
    @endif

    <div class="summary-box">
        <div class="summary-card">
            <div class="label">Facturado</div>
            <div class="value">L {{ number_format($totals['billed_total'], 2) }}</div>
            <div class="sub">Entró {{ $totals['entered']['label'] }}</div>
        </div>
        <div class="summary-card">
            <div class="label">De eso bonificación</div>
            <div class="value">{{ $totals['bonus']['label'] }}</div>
            <div class="sub">Mercadería con valor L 0.00</div>
        </div>
        <div class="summary-card returned">
            <div class="label">Devuelto</div>
            <div class="value">L {{ number_format($totals['returned_total'], 2) }}</div>
            <div class="sub">{{ $totals['returned']['label'] }} · {{ number_format($totals['return_pct'], 1) }}% de lo facturado</div>
        </div>
        <div class="summary-card net">
            <div class="label">Venta neta</div>
            <div class="value">L {{ number_format($totals['net_total'], 2) }}</div>
            <div class="sub">Se movió {{ $totals['net']['label'] }}</div>
        </div>
    </div>

    @if (count($report['rows']) > 0)
    <table class="data">
        <colgroup>
            <col class="c-num">
            <col class="c-code">
            <col class="c-desc">
            <col class="c-udc">
            <col class="c-qty">
            <col class="c-qty">
            <col class="c-qty">
            <col class="c-qty">
            <col class="c-pct">
            <col class="c-amt">
            <col class="c-amt">
            <col class="c-amt">
        </colgroup>
        <thead>
            <tr>
                <th class="c">#</th>
                <th class="l">Código</th>
                <th class="l">Descripción</th>
                <th class="c">Udc.</th>
                <th class="sep">Entró</th>
                <th>De eso bonif.</th>
                <th>Devuelto</th>
                <th>Neto movido</th>
                <th>% Dev.</th>
                <th class="sep">Facturado L</th>
                <th>Devuelto L</th>
                <th>Neto L</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($report['rows'] as $i => $row)
            <tr>
                <td class="row-num">{{ $i + 1 }}</td>
                <td class="code">{{ $row['product_id'] }}</td>
                <td class="l">{{ $row['description'] }}</td>
                <td class="c">{{ $row['unit_sale'] ?? '—' }}</td>
                <td class="sep">{{ $row['entered']['label'] }}</td>
                <td class="{{ $row['bonus_units'] > 0 ? 'bonus' : 'zero' }}">{{ $row['bonus']['label'] }}</td>
                <td class="{{ $row['returned_units'] > 0 ? 'ret' : 'zero' }}">{{ $row['returned']['label'] }}</td>
                <td class="net">{{ $row['net']['label'] }}</td>
                <td class="{{ $row['return_pct'] > 0 ? 'ret' : 'zero' }}">{{ number_format($row['return_pct'], 1) }}%</td>
                <td class="sep">{{ number_format($row['billed_amount'], 2) }}</td>
                <td class="{{ $row['returned_amount'] > 0 ? 'ret' : 'zero' }}">{{ number_format($row['returned_amount'], 2) }}</td>
                <td class="net">{{ number_format($row['net_amount'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="l" colspan="4">TOTAL — {{ number_format($totals['products_count']) }} productos</td>
                <td>{{ $totals['entered']['label'] }}</td>
                <td>{{ $totals['bonus']['label'] }}</td>
                <td>{{ $totals['returned']['label'] }}</td>
                <td>{{ $totals['net']['label'] }}</td>
                <td>{{ number_format($totals['return_pct'], 1) }}%</td>
                <td>{{ number_format($totals['billed_total'], 2) }}</td>
                <td>{{ number_format($totals['returned_total'], 2) }}</td>
                <td>{{ number_format($totals['net_total'], 2) }}</td>
            </tr>
        </tfoot>
    </table>
    @else
    <p style="text-align:center; color:#888; padding:20px;">No hay facturas en este período para las bodegas elegidas.</p>
    @endif

    <div class="notes">
        <strong>Cómo se lee:</strong> Neto movido = Entró − Devuelto. La bonificación ya viene incluida en "Entró" (mercadería con valor L 0.00) y no se resta.
        Cantidades en cajas (cj) y unidades sueltas (u).
        Las devoluciones son las de las facturas del período, aunque se hayan registrado después; las canceladas no cuentan.
        El % de cada producto es sobre las unidades que entraron; el % del total, sobre los lempiras facturados.
        Los totales en lempiras son los de las facturas (lo que se deposita); la suma de las filas puede diferir unos centavos por el redondeo del proveedor.
        No incluye facturas rechazadas ni anuladas.
    </div>

    <div class="report-footer">
        Distribuciones Alianza &nbsp;·&nbsp; {{ $report['generated_at'] }} &nbsp;·&nbsp; {{ $reportNumber }}
    </div>
</div>
</div>
</body>
</html>
