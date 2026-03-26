<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<title>Sublista Facturas — Manifiesto #{{ $manifest->number }}</title>
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
    margin: 0 auto 16px auto; padding: 8mm;
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
.header-right { text-align: right; padding-left: 20px; }
.report-header .company {
    font-size: 13pt;
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

/* ── Route header ────────────────────────────────────────────── */
.route-header {
    background: linear-gradient(135deg, #e2e8f0 0%, #f1f5f9 100%);
    padding: 5px 10px;
    font-weight: bold;
    font-size: 8.5pt;
    border-left: 3px solid #1e3a5f;
    margin-top: 14px;
    margin-bottom: 2px;
    display: flex;
    justify-content: space-between;
    color: #334155;
}

/* ── Table ────────────────────────────────────────────────────── */
table.data { width: 100%; border-collapse: collapse; font-size: 7.5pt; margin-bottom: 0; table-layout: fixed; }
table.data col.col-num     { width: 22px; }
table.data col.col-factura { width: 120px; }
table.data col.col-cliente { /* auto fill */ }
table.data col.col-total   { width: 70px; }
table.data col.col-check   { width: 24px; }
table.data thead tr { background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%); color: #fff; }
table.data thead th {
    padding: 5px 6px;
    text-align: left;
    white-space: nowrap;
    font-size: 7pt;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}
table.data thead th.r { text-align: right; }
table.data thead th.c { text-align: center; }
table.data tbody tr { page-break-inside: avoid; break-inside: avoid; }
table.data tbody tr:nth-child(even) { background: #f8fafc; }
table.data tbody td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
table.data tbody td.r { text-align: right; font-variant-numeric: tabular-nums; }
table.data tbody td.c { text-align: center; }
table.data tbody .row-num { color: #94a3b8; font-size: 6.5pt; text-align: center; }
.subtotal-row { background: #dbeafe !important; font-weight: bold; }
.subtotal-row td { padding: 5px 6px; font-size: 7.5pt; border-top: 1px solid #93c5fd; }
table.data tfoot tr { background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%); color: #fff; font-weight: bold; }
table.data tfoot td { padding: 5px 6px; font-size: 7.5pt; }
table.data tfoot td.r { text-align: right; }
table.data tfoot td.c { text-align: center; }

.checkbox {
    display: inline-block;
    width: 11px;
    height: 11px;
    border: 1.5px solid #94a3b8;
    border-radius: 2px;
    background: #fff;
}

/* ── Pagination ──────────────────────────────────────────────── */
table.data thead { display: table-header-group; }
table.data tfoot { display: table-footer-group; }

/* ── Verification section ────────────────────────────────────── */
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
.signature-area { margin-top: 20px; display: flex; gap: 40px; }
.signature-block { flex: 1; text-align: center; }
.signature-line {
    border-top: 1px solid #64748b;
    margin-top: 40px;
    padding-top: 5px;
    font-size: 7.5pt;
    color: #64748b;
}

/* ── Footer ──────────────────────────────────────────────────── */
.report-footer {
    margin-top: 16px;
    border-top: 2px solid #e2e8f0;
    padding-top: 8px;
    font-size: 7pt;
    color: #94a3b8;
    text-align: center;
    letter-spacing: 0.3px;
}

/* ── Route page break ─────────────────────────────────────────── */
.route-break { page-break-after: always; break-after: page; }

/* ── Print ────────────────────────────────────────────────────── */
@media print {
    @page { size: A4 portrait; margin: 8mm; }
    #toolbar { display: none !important; }
    body { background: none; }
    #report-container { margin-top: 0; padding: 0; }
    .report-page { width: 100%; margin: 0; padding: 5mm; box-shadow: none; }
    .subtotal-row { background: #dbeafe !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.data thead tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.data tfoot tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>

<div id="toolbar">
    <div class="info">
        <strong>Sublista Facturas — Manifiesto #{{ $manifest->number }}</strong>
        <span>{{ $totals['count'] }} facturas · {{ $byRoute->count() }} rutas · Generado: {{ $generatedAt }}</span>
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
                <div class="title">Sublista de Facturas</div>
                <div class="meta">
                    Fecha Manifiesto: {{ $manifest->date ? \Carbon\Carbon::parse($manifest->date)->format('d/m/Y') : '—' }}
                    &nbsp;·&nbsp; Generado: {{ $generatedAt }}
                    @if($warehouseFiltered)
                        <br>Bodega: <strong>{{ $warehouseName }}</strong>
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
            <div class="label">Total Facturas</div>
            <div class="value">{{ number_format($totals['count']) }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Rutas</div>
            <div class="value">{{ $byRoute->count() }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Clientes</div>
            <div class="value">{{ $totals['clients'] }}</div>
        </div>
        <div class="summary-card highlight">
            <div class="label">Total General</div>
            <div class="value">L {{ number_format($totals['total'], 2) }}</div>
        </div>
    </div>

    {{-- FACTURAS AGRUPADAS POR RUTA --}}
    @forelse($byRoute as $route => $routeData)
    <div class="route-block {{ !$loop->last ? 'route-break' : '' }}">
    <div class="route-header">
        <span>Ruta: {{ $route ?: '(Sin ruta)' }} — {{ $routeData['count'] }} facturas</span>
        <span>Subtotal: L {{ number_format($routeData['subtotal'], 2) }}</span>
    </div>
    <table class="data">
        <colgroup>
            <col class="col-num">
            <col class="col-factura">
            <col class="col-cliente">
            <col class="col-total">
            <col class="col-check">
        </colgroup>
        <thead>
            <tr>
                <th class="c">#</th>
                <th># Factura</th>
                <th>Cliente</th>
                <th class="r">Total (HNL)</th>
                <th class="c">&#10003;</th>
            </tr>
        </thead>
        <tbody>
            @foreach($routeData['invoices'] as $idx => $invoice)
            <tr>
                <td class="row-num">{{ $idx + 1 }}</td>
                <td><strong>{{ $invoice->invoice_number }}</strong></td>
                <td>{{ $invoice->client_name }}</td>
                <td class="r"><strong>L {{ number_format($invoice->total, 2) }}</strong></td>
                <td class="c"><span class="checkbox"></span></td>
            </tr>
            @endforeach
            <tr class="subtotal-row">
                <td colspan="3">Subtotal Ruta {{ $route ?: '(Sin ruta)' }} — {{ $routeData['count'] }} facturas</td>
                <td class="r">L {{ number_format($routeData['subtotal'], 2) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
    </div>
    @empty
    <p style="text-align:center; color:#888; padding:20px;">No hay facturas para este manifiesto.</p>
    @endforelse

    {{-- TOTAL GENERAL --}}
    @if($byRoute->count() > 0)
    <table class="data" style="margin-top:6px;">
        <colgroup>
            <col class="col-num">
            <col class="col-factura">
            <col class="col-cliente">
            <col class="col-total">
            <col class="col-check">
        </colgroup>
        <tfoot>
            <tr>
                <td colspan="3"><strong>TOTAL GENERAL — {{ $totals['count'] }} facturas</strong></td>
                <td class="r">L {{ number_format($totals['total'], 2) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    @endif

    {{-- VERIFICATION SECTION --}}
    @if($totals['count'] > 0)
    <div class="verification-section">
        <div class="verification-title">Verificacion de Entrega</div>
        <ul class="check-list">
            <li><span class="checkbox"></span> Se verifico que todas las facturas coinciden con el manifiesto fisico</li>
            <li><span class="checkbox"></span> Se confirmo que los totales por ruta son correctos</li>
            <li><span class="checkbox"></span> Se verifico que el total general coincide con el documento de Jaremar</li>
            <li><span class="checkbox"></span> Se reportaron discrepancias al administrador (si aplica)</li>
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
