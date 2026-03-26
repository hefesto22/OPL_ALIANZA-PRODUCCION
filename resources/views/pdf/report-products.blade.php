<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<title>Sublista Productos — Manifiesto #{{ $manifest->number }}</title>
<style>
/* ── Toolbar (solo pantalla) ─────────────────────────────────── */
#toolbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 9999;
    background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%);
    color: #fff;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-family: Arial, sans-serif;
    font-size: 13px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.3);
}
#toolbar .info strong { font-size: 15px; display: block; }
#toolbar .info span   { font-size: 12px; color: #aac4e8; }
#toolbar .btn-print {
    background: #f59e0b; color: #000; border: none;
    padding: 8px 22px; border-radius: 6px;
    font-size: 14px; font-weight: bold; cursor: pointer;
    transition: background 0.2s;
}
#toolbar .btn-print:hover { background: #d97706; }

/* ── Base ─────────────────────────────────────────────────────── */
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 9pt; color: #1a1a1a; background: #e5e7eb; margin: 0; }
#report-container { margin-top: 60px; padding: 16px; }
.report-page {
    background: #fff; width: 190mm;
    margin: 0 auto 16px auto; padding: 8mm 8mm;
    box-shadow: 0 2px 12px rgba(0,0,0,0.12); box-sizing: border-box;
}

/* ── Header ───────────────────────────────────────────────────── */
.report-header {
    border-bottom: 3px solid #1e3a5f;
    padding-bottom: 10px;
    margin-bottom: 14px;
}
.header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}
.header-left { flex: 1; }
.header-right {
    text-align: right;
    padding-left: 20px;
}
.report-header .company {
    font-size: 14pt;
    font-weight: bold;
    color: #1e3a5f;
    letter-spacing: 0.5px;
}
.report-header .title {
    font-size: 11pt;
    font-weight: bold;
    margin: 4px 0 2px;
    color: #333;
}
.report-header .meta { font-size: 8pt; color: #666; line-height: 1.6; }
.manifest-badge {
    display: inline-block;
    background: #1e3a5f;
    color: #fff;
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 13pt;
    font-weight: bold;
    letter-spacing: 1px;
}

/* ── Summary cards ────────────────────────────────────────────── */
.summary-box { display: flex; gap: 10px; margin-bottom: 16px; }
.summary-card {
    flex: 1;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 10px 12px;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    text-align: center;
    border-top: 3px solid #1e3a5f;
}
.summary-card.highlight { border-top-color: #16a34a; }
.summary-card .label { font-size: 7pt; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
.summary-card .value { font-size: 12pt; font-weight: bold; color: #1e3a5f; margin-top: 3px; }
.summary-card.highlight .value { color: #16a34a; }

/* ── Table ────────────────────────────────────────────────────── */
table.data { width: 100%; border-collapse: collapse; font-size: 6.5pt; margin-bottom: 0; table-layout: fixed; }
table.data col.col-num     { width: 16px; }
table.data col.col-code    { width: 46px; }
table.data col.col-desc    { /* auto fill */ }
table.data col.col-udc     { width: 22px; }
table.data col.col-boxes   { width: 30px; }
table.data col.col-units   { width: 30px; }
table.data col.col-total   { width: 72px; }
table.data col.col-recv    { width: 30px; }
table.data col.col-check   { width: 18px; }
td.write-cell { background: #fffef5 !important; border: 1px dashed #cbd5e1 !important; min-height: 16px; }
table.data thead tr { background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%); color: #fff; }
table.data thead th {
    padding: 3px 3px;
    text-align: left;
    white-space: nowrap;
    font-size: 6pt;
    text-transform: uppercase;
    letter-spacing: 0;
}
table.data thead th.r { text-align: right; }
table.data thead th.c { text-align: center; }
table.data tbody tr { transition: background 0.1s; }
table.data tbody tr:nth-child(even) { background: #f8fafc; }
table.data tbody tr:hover { background: #eef2ff; }
table.data tbody td { padding: 3px 3px; border-bottom: 1px solid #e5e7eb; }
table.data tbody td.r { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
table.data tbody td.c { text-align: center; }
table.data tbody td.code { font-family: 'Consolas', 'Courier New', monospace; font-size: 6.5pt; color: #475569; }
table.data tbody .row-num { color: #94a3b8; font-size: 6pt; text-align: center; width: 20px; }
table.data tfoot tr { background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%); color: #fff; font-weight: bold; }
table.data tfoot td { padding: 5px 4px; font-size: 7pt; }
table.data tfoot td.r { text-align: right; white-space: nowrap; }
table.data tfoot td.c { text-align: center; }

/* ── Verification section ─────────────────────────────────────── */
.verification-section {
    margin-top: 24px;
    border: 2px solid #cbd5e1;
    border-radius: 8px;
    padding: 16px 18px;
    background: #fafbfc;
    page-break-inside: avoid;
}
.verification-title {
    font-size: 9pt;
    font-weight: bold;
    color: #1e3a5f;
    margin-bottom: 12px;
    padding-bottom: 6px;
    border-bottom: 1px solid #e2e8f0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.check-list { list-style: none; padding: 0; margin: 0; }
.check-list li {
    padding: 7px 0;
    border-bottom: 1px dashed #e2e8f0;
    font-size: 8.5pt;
    color: #334155;
    display: flex;
    align-items: center;
    gap: 10px;
}
.check-list li:last-child { border-bottom: none; }
.checkbox {
    display: inline-block;
    width: 12px;
    height: 12px;
    border: 1.5px solid #94a3b8;
    border-radius: 2px;
    flex-shrink: 0;
    background: #fff;
}
.signature-area {
    margin-top: 20px;
    display: flex;
    gap: 40px;
}
.signature-block {
    flex: 1;
    text-align: center;
}
.signature-line {
    border-top: 1px solid #64748b;
    margin-top: 40px;
    padding-top: 5px;
    font-size: 7.5pt;
    color: #64748b;
}

/* ── Footer ───────────────────────────────────────────────────── */
.report-footer {
    margin-top: 16px;
    border-top: 2px solid #e2e8f0;
    padding-top: 8px;
    font-size: 7pt;
    color: #94a3b8;
    text-align: center;
    letter-spacing: 0.3px;
}

/* ── Pagination (print + screen) ──────────────────────────────── */
table.data thead { display: table-header-group; }
table.data tfoot { display: table-row-group; }
table.data tbody tr { page-break-inside: avoid; break-inside: avoid; }
.verification-section { page-break-inside: avoid; break-inside: avoid; }

/* ── Print ────────────────────────────────────────────────────── */
@media print {
    @page { size: A4 portrait; margin: 8mm; }
    #toolbar { display: none !important; }
    body { background: none; }
    #report-container { margin-top: 0; padding: 0; }
    .report-page { width: 100%; margin: 0; padding: 5mm; box-shadow: none; page-break-after: avoid; }
    table.data tbody tr:hover { background: inherit; }
    .verification-section { border-color: #999; page-break-before: auto; }
}
</style>
</head>
<body>

<div id="toolbar">
    <div class="info">
        <strong>Sublista Productos — Manifiesto #{{ $manifest->number }}</strong>
        <span>{{ $totals['count'] }} productos · Generado: {{ $generatedAt }}</span>
    </div>
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
</div>

<div id="report-container">
<div class="report-page">

    {{-- HEADER --}}
    <div class="report-header">
        <div class="header-row">
            <div class="header-left">
                <div class="company">{{ $supplier?->name ?? 'Grupo Jaremar de Honduras S.A. de C.V.' }}</div>
                <div class="title">Sublista de Productos</div>
                <div class="meta">
                    Fecha Manifiesto: {{ $manifest->date ? \Carbon\Carbon::parse($manifest->date)->format('d/m/Y') : '—' }}
                    &nbsp;·&nbsp; Generado: {{ $generatedAt }}
                    @if($warehouseFiltered && $manifest->warehouse)
                        <br>Bodega: <strong>{{ $manifest->warehouse->code }} — {{ $manifest->warehouse->name }}</strong>
                    @endif
                </div>
            </div>
            <div class="header-right">
                <div class="manifest-badge">#{{ $manifest->number }}</div>
            </div>
        </div>
    </div>

    {{-- SUMMARY CARDS --}}
    <div class="summary-box">
        <div class="summary-card">
            <div class="label">Productos</div>
            <div class="value">{{ number_format($totals['count']) }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Total Cajas</div>
            <div class="value">{{ number_format((float) $totals['total_boxes']) }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Total Unidades</div>
            <div class="value">{{ number_format((float) $totals['total_units']) }}</div>
        </div>
        <div class="summary-card highlight">
            <div class="label">Total General</div>
            <div class="value">L {{ number_format((float) $totals['total_amount'], 2) }}</div>
        </div>
    </div>

    {{-- TABLE --}}
    @if($products->count() > 0)
    <table class="data">
        <colgroup>
            <col class="col-num">
            <col class="col-code">
            <col class="col-desc">
            <col class="col-udc">
            <col class="col-boxes">
            <col class="col-units">
            <col class="col-total">
            <col class="col-recv">
            <col class="col-check">
        </colgroup>
        <thead>
            <tr>
                <th>#</th>
                <th>Codigo</th>
                <th>Descripcion</th>
                <th class="c">Udc.</th>
                <th class="c">Cajas</th>
                <th class="c">Unid.</th>
                <th class="r">Total (HNL)</th>
                <th class="c">Recib.</th>
                <th class="c">&#10003;</th>
            </tr>
        </thead>
        <tbody>
            @foreach($products as $i => $product)
            <tr>
                <td class="row-num">{{ $i + 1 }}</td>
                <td class="code">{{ $product->product_id }}</td>
                <td>{{ $product->product_description }}</td>
                <td class="c">{{ $product->unit_sale ?? '—' }}</td>
                <td class="c">{{ strtoupper($product->unit_sale) === 'CJ' ? number_format((float) $product->total_boxes, 0) : '' }}</td>
                <td class="c">{{ strtoupper($product->unit_sale) !== 'CJ' ? number_format((float) $product->total_units, 0) : '' }}</td>
                <td class="r"><strong>L {{ number_format((float) $product->total_amount, 2) }}</strong></td>
                <td class="c write-cell"></td>
                <td class="c"><span class="checkbox"></span></td>
            </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4"><strong>TOTAL GENERAL — {{ $totals['count'] }} productos</strong></td>
                <td class="c">{{ number_format((float) $totals['total_boxes']) }}</td>
                <td class="c">{{ number_format((float) $totals['total_units']) }}</td>
                <td class="r">L {{ number_format((float) $totals['total_amount'], 2) }}</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
    @else
    <p style="text-align:center; color:#888; padding:20px;">No se encontraron productos para este manifiesto.</p>
    @endif

    {{-- VERIFICATION CHECKLIST --}}
    @if($products->count() > 0)
    <div class="verification-section">
        <div class="verification-title">Verificacion de Entrega</div>
        <ul class="check-list">
            <li><span class="checkbox"></span> Se verificaron las cantidades de cajas contra el manifiesto fisico</li>
            <li><span class="checkbox"></span> Se verificaron los productos recibidos contra la sublista</li>
            <li><span class="checkbox"></span> No se encontraron productos danados o en mal estado</li>
            <li><span class="checkbox"></span> Se verificaron las unidades sueltas</li>
            <li><span class="checkbox"></span> La mercaderia fue almacenada correctamente en bodega</li>
            <li><span class="checkbox"></span> Se reportaron productos danados o faltantes al administrador</li>
        </ul>

        <div class="signature-area">
            <div class="signature-block">
                <div class="signature-line">Entregado por — Nombre y Firma</div>
            </div>
            <div class="signature-block">
                <div class="signature-line">Recibido por — Nombre y Firma</div>
            </div>
            <div class="signature-block">
                <div class="signature-line">Fecha de Verificacion</div>
            </div>
        </div>
    </div>
    @endif

    <div class="report-footer">
        Distribuidora Hosana &nbsp;·&nbsp; {{ $generatedAt }} &nbsp;·&nbsp; Manifiesto #{{ $manifest->number }}
    </div>
</div>
</div>
</body>
</html>
