<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<title>Reporte de Manifiestos — Distribuidora Hosana</title>
<style>
/* ══ TOOLBAR ═══════════════════════════════════════════════════ */
#toolbar {
    position: fixed;
    top: 0; left: 0; right: 0;
    z-index: 9999;
    background: #1e3a5f;
    color: #fff;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-family: Arial, sans-serif;
    font-size: 13px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}
#toolbar .info strong { font-size: 15px; display: block; }
#toolbar .info span   { font-size: 12px; color: #aac4e8; }
#toolbar .btn-print {
    background: #f59e0b;
    color: #000;
    border: none;
    padding: 8px 22px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
    white-space: nowrap;
}
#toolbar .btn-print:hover { background: #d97706; }

/* ══ PÁGINA ═════════════════════════════════════════════════════ */
body {
    font-family: Arial, sans-serif;
    font-size: 9pt;
    color: #111;
    background: #e5e7eb;
    margin: 0;
    padding: 0;
}
#report-container {
    margin-top: 0;
    padding: 16px;
    padding-top: 0;
}
.report-page {
    background: #fff;
    width: 270mm;
    margin: 0 auto 16px auto;
    padding: 12mm 13mm 12mm 13mm;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    box-sizing: border-box;
}

/* ══ ENCABEZADO ═════════════════════════════════════════════════ */
.report-header {
    border-bottom: 2px solid #1e3a5f;
    padding-bottom: 8px;
    margin-bottom: 12px;
}
.report-header table { width: 100%; border-collapse: collapse; }
.report-header .left  { vertical-align: top; width: 70%; }
.report-header .right { vertical-align: top; text-align: right; width: 30%; }
.report-header .company { font-size: 13pt; font-weight: bold; color: #1e3a5f; }
.report-header .title   { font-size: 10.5pt; font-weight: bold; margin: 2px 0 1px 0; }
.report-header .meta    { font-size: 7.5pt; color: #555; margin-top: 3px; line-height: 1.5; }
.report-header .report-num {
    font-size: 7.5pt;
    color: #1e3a5f;
    font-weight: bold;
}
.report-header .report-meta-right {
    font-size: 7pt;
    color: #666;
    margin-top: 3px;
    line-height: 1.6;
}

/* ══ RESUMEN EJECUTIVO ══════════════════════════════════════════ */
.summary-row {
    display: flex;
    gap: 8px;
    margin-bottom: 6px;
}
.summary-section { margin-bottom: 12px; }
.summary-card {
    flex: 1;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 7px 8px;
    background: #f8fafc;
    text-align: center;
}
.summary-card .label { font-size: 7pt; color: #555; line-height: 1.3; }
.summary-card .value { font-size: 10.5pt; font-weight: bold; color: #1e3a5f; margin-top: 2px; }
.summary-card.green .value { color: #166534; }
.summary-card.red   .value { color: #991b1b; }
.summary-card.amber .value { color: #92400e; }
.summary-card.gray  .value { color: #374151; }

/* ══ TABLA ══════════════════════════════════════════════════════ */
table.data {
    width: 100%;
    border-collapse: collapse;
    font-size: 7.5pt;
    margin-top: 4px;
}
table.data thead tr {
    background: #1e3a5f;
    color: #fff;
}
table.data thead th {
    padding: 5px 5px;
    text-align: left;
    font-weight: bold;
    white-space: nowrap;
}
table.data thead th.r { text-align: right; }
table.data thead th.c { text-align: center; }
table.data tbody tr:nth-child(even) { background: #f1f5f9; }
table.data tbody td {
    padding: 4px 5px;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
}
table.data tbody td.r { text-align: right; }
table.data tbody td.c { text-align: center; }
table.data tfoot tr { background: #1e3a5f; color: #fff; font-weight: bold; }
table.data tfoot td {
    padding: 5px 5px;
    font-size: 7.5pt;
}
table.data tfoot td.r { text-align: right; }

/* ══ BADGES ═════════════════════════════════════════════════════ */
.badge {
    display: inline-block;
    padding: 1px 5px;
    border-radius: 8px;
    font-size: 6.5pt;
    font-weight: bold;
}
.badge-closed     { background: #dcfce7; color: #166534; }
.badge-imported   { background: #dbeafe; color: #1e40af; }
.badge-pending    { background: #f3f4f6; color: #374151; }
.badge-processing { background: #fef3c7; color: #92400e; }

.diff-ok  { color: #166534; font-weight: bold; }
.diff-bad { color: #991b1b; font-weight: bold; }

/* ══ CONCILIACIÓN ═══════════════════════════════════════════════ */
.conciliation {
    margin-top: 14px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    overflow: hidden;
}
.conciliation .conc-header {
    background: #1e3a5f;
    color: #fff;
    font-size: 8pt;
    font-weight: bold;
    padding: 5px 10px;
    letter-spacing: 0.3px;
}
.conciliation table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8pt;
}
.conciliation table td {
    padding: 4px 10px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}
.conciliation table td.amt { text-align: right; font-weight: bold; white-space: nowrap; }
.conciliation .row-total  { background: #f8fafc; }
.conciliation .row-ret    { background: #fff5f5; color: #991b1b; }
.conciliation .row-dep    { background: #eef2f7; font-weight: bold; }
.conciliation .row-pago   { background: #f0fdf4; }
.conciliation .row-dif    { background: #f8fafc; }
.conciliation .row-dif.ok td { color: #166534; }
.conciliation .row-dif.bad td { color: #991b1b; }
.conciliation .sign-minus { color: #dc2626; font-size: 9pt; font-weight: bold; }
.conciliation .sign-equal { color: #1e3a5f; font-size: 9pt; font-weight: bold; }
.conciliation .sign-total { display: inline-block; width: 12px; }

/* ══ FIRMAS ═════════════════════════════════════════════════════ */
.signature-section {
    margin-top: 28px;
    display: flex;
    gap: 40px;
}
.signature-block { flex: 1; text-align: center; }
.signature-line {
    border-top: 1px solid #333;
    margin-bottom: 4px;
    padding-top: 4px;
}
.signature-label { font-size: 7.5pt; color: #374151; font-weight: bold; }
.signature-sub   { font-size: 6.5pt; color: #9ca3af; margin-top: 2px; }

/* ══ PIE ════════════════════════════════════════════════════════ */
.report-footer {
    margin-top: 12px;
    border-top: 1px solid #e5e7eb;
    padding-top: 5px;
    font-size: 6.5pt;
    color: #9ca3af;
    text-align: center;
    line-height: 1.6;
}

/* ══ IMPRESIÓN ══════════════════════════════════════════════════ */
@media print {
    @page { size: 270mm auto portrait; margin: 0; }
    #toolbar { display: none !important; }
    body { background: none; }
    #report-container { margin-top: 0 !important; padding: 0; padding-top: 0 !important; }
    .report-page {
        width: 270mm;
        margin: 0;
        box-shadow: none;
        page-break-after: always;
    }
    .report-page:last-child { page-break-after: avoid; }
}
</style>
</head>
<body>

<div id="toolbar">
    <div class="info">
        <strong>Reporte de Manifiestos &nbsp;·&nbsp; No. {{ $reportNumber }}</strong>
        <span>
            @if(!empty($filters['date_from']) || !empty($filters['date_to']))
                Período:
                {{ !empty($filters['date_from']) ? \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') : '—' }}
                al
                {{ !empty($filters['date_to']) ? \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') : '—' }}
                &nbsp;·&nbsp;
            @endif
            {{ $manifests->count() }} manifiesto(s) &nbsp;·&nbsp; {{ number_format($totals['invoices_count']) }} facturas
        </span>
    </div>
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
</div>

<div id="report-container">
<div class="report-page">

    {{-- ══ ENCABEZADO ════════════════════════════════════════════ --}}
    <div class="report-header">
        <table>
            <tr>
                <td class="left">
                    <div class="company">Distribuidora Hosana</div>
                    <div class="title">Reporte de Manifiestos</div>
                    <div class="meta" style="margin-top:4px;">
                        Proveedor: <strong>{{ $supplier?->name ?? 'Grupo Jaremar de Honduras S.A. de C.V.' }}</strong>
                        @if($supplier?->rtn)
                            &nbsp;·&nbsp; RTN Proveedor: {{ $supplier->rtn }}
                        @endif
                    </div>
                    @if(!empty($filters['date_from']) || !empty($filters['date_to']) || !empty($filters['status']))
                    <div class="meta" style="margin-top:2px;">
                        Filtros aplicados:
                        @if(!empty($filters['date_from']))Desde {{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }}@endif
                        @if(!empty($filters['date_to'])) hasta {{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }}@endif
                        @if(!empty($filters['status'])) &nbsp;·&nbsp; Estado: {{ match($filters['status']) { 'closed' => 'Cerrado', 'imported' => 'Importado', 'pending' => 'Pendiente', 'processing' => 'Procesando', default => $filters['status'] } }}@endif
                    </div>
                    @endif
                </td>
                <td class="right">
                    <div class="report-num">No. {{ $reportNumber }}</div>
                    <div class="report-meta-right">
                        Generado: {{ $generatedAt }}<br>
                        Manifiestos: <strong>{{ $manifests->count() }}</strong><br>
                        Facturas: <strong>{{ number_format($totals['invoices_count']) }}</strong><br>
                        Clientes únicos: <strong>{{ number_format($totals['clients_count']) }}</strong>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ══ RESUMEN EJECUTIVO — fila 1: conteos ═══════════════════ --}}
    <div class="summary-section">
        <div class="summary-row">
            <div class="summary-card">
                <div class="label">Total Manifiestos</div>
                <div class="value">{{ $manifests->count() }}</div>
            </div>
            <div class="summary-card green">
                <div class="label">Cerrados</div>
                <div class="value">{{ $totals['closed_count'] }}</div>
            </div>
            <div class="summary-card amber">
                <div class="label">Abiertos</div>
                <div class="value">{{ $totals['open_count'] }}</div>
            </div>
            <div class="summary-card">
                <div class="label">Total Facturas</div>
                <div class="value">{{ number_format($totals['invoices_count']) }}</div>
            </div>
            <div class="summary-card gray">
                <div class="label">Clientes Únicos</div>
                <div class="value">{{ number_format($totals['clients_count']) }}</div>
            </div>
        </div>
        {{-- fila 2: montos --}}
        <div class="summary-row">
            <div class="summary-card">
                <div class="label">Total Facturado</div>
                <div class="value" style="font-size:8pt;">L {{ number_format($totals['total_invoices'], 2) }}</div>
            </div>
            <div class="summary-card red">
                <div class="label">Total Devoluciones</div>
                <div class="value" style="font-size:8pt;">L {{ number_format($totals['total_returns'], 2) }}</div>
            </div>
            <div class="summary-card amber">
                <div class="label">Total A Depositar</div>
                <div class="value" style="font-size:8pt;">L {{ number_format($totals['total_to_deposit'], 2) }}</div>
            </div>
            <div class="summary-card green">
                <div class="label">Total Depositado</div>
                <div class="value" style="font-size:8pt;">L {{ number_format($totals['total_deposited'], 2) }}</div>
            </div>
            @php $difTotal = $totals['difference']; @endphp
            <div class="summary-card {{ $difTotal == 0 ? 'green' : 'red' }}">
                <div class="label">Diferencia Total</div>
                <div class="value" style="font-size:8pt;">L {{ number_format($difTotal, 2) }}</div>
            </div>
        </div>
    </div>

    {{-- ══ TABLA DETALLE ══════════════════════════════════════════ --}}
    <table class="data">
        <thead>
            <tr>
                <th># Manif.</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Bodega</th>
                <th class="c">Fact.</th>
                <th class="c">Clientes</th>
                <th class="r">Total Facturado</th>
                <th class="r">Devoluciones</th>
                <th class="r">A Depositar</th>
                <th class="r">Depositado</th>
                <th class="r">Diferencia</th>
                <th>Fecha Cierre</th>
            </tr>
        </thead>
        <tbody>
            @forelse($manifests as $manifest)
            <tr>
                <td><strong>{{ $manifest->number }}</strong></td>
                <td>{{ $manifest->date ? \Carbon\Carbon::parse($manifest->date)->format('d/m/Y') : '—' }}</td>
                <td>
                    <span class="badge badge-{{ $manifest->status }}">
                        {{ match($manifest->status) { 'closed' => 'Cerrado', 'imported' => 'Importado', 'pending' => 'Pendiente', 'processing' => 'Procesando', default => $manifest->status } }}
                    </span>
                </td>
                <td>{{ $manifest->warehouse?->code ?? '—' }}</td>
                <td class="c">{{ number_format($manifest->invoices_count) }}</td>
                <td class="c">{{ number_format($manifest->clients_count ?? 0) }}</td>
                <td class="r">L {{ number_format($manifest->total_invoices, 2) }}</td>
                <td class="r" style="color:#991b1b;">L {{ number_format($manifest->total_returns, 2) }}</td>
                <td class="r" style="color:#92400e; font-weight:bold;">L {{ number_format($manifest->total_to_deposit, 2) }}</td>
                <td class="r" style="color:#166534;">L {{ number_format($manifest->total_deposited, 2) }}</td>
                <td class="r {{ $manifest->difference == 0 ? 'diff-ok' : 'diff-bad' }}">
                    L {{ number_format($manifest->difference, 2) }}
                </td>
                <td>{{ $manifest->closed_at ? \Carbon\Carbon::parse($manifest->closed_at)->format('d/m/Y') : '—' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="12" style="text-align:center; padding:20px; color:#9ca3af;">
                    No se encontraron manifiestos con los filtros aplicados.
                </td>
            </tr>
            @endforelse
        </tbody>
        @if($manifests->count() > 0)
        <tfoot>
            <tr>
                <td colspan="4"><strong>TOTALES</strong></td>
                <td class="r">{{ number_format($totals['invoices_count']) }}</td>
                <td class="r">{{ number_format($totals['clients_count']) }}</td>
                <td class="r">L {{ number_format($totals['total_invoices'], 2) }}</td>
                <td class="r">L {{ number_format($totals['total_returns'], 2) }}</td>
                <td class="r">L {{ number_format($totals['total_to_deposit'], 2) }}</td>
                <td class="r">L {{ number_format($totals['total_deposited'], 2) }}</td>
                <td class="r">L {{ number_format($totals['difference'], 2) }}</td>
                <td></td>
            </tr>
        </tfoot>
        @endif
    </table>

    {{-- ══ CONCILIACIÓN CONTABLE ══════════════════════════════════ --}}
    @if($manifests->count() > 0)
    @php $difFinal = $totals['difference']; @endphp
    <div class="conciliation">
        <div class="conc-header">CONCILIACIÓN CONTABLE — CUADRE DE VALORES</div>
        <table>
            <tr class="row-total">
                <td style="padding-left:20px;"><span class="sign-total">&nbsp;</span> Total Facturado</td>
                <td class="amt" style="width:160px;">L {{ number_format($totals['total_invoices'], 2) }}</td>
            </tr>
            <tr class="row-ret">
                <td style="padding-left:20px;"><span class="sign-minus sign-total">(−)</span> Total Devoluciones</td>
                <td class="amt">L {{ number_format($totals['total_returns'], 2) }}</td>
            </tr>
            <tr class="row-dep">
                <td style="padding-left:20px; font-size:8.5pt;"><span class="sign-equal sign-total">(=)</span> <strong>Total A Depositar</strong></td>
                <td class="amt" style="font-size:8.5pt; color:#92400e;">L {{ number_format($totals['total_to_deposit'], 2) }}</td>
            </tr>
            <tr class="row-pago">
                <td style="padding-left:20px;"><span class="sign-minus sign-total">(−)</span> Total Depositado</td>
                <td class="amt" style="color:#166534;">L {{ number_format($totals['total_deposited'], 2) }}</td>
            </tr>
            <tr class="row-dif {{ $difFinal == 0 ? 'ok' : 'bad' }}">
                <td style="padding-left:20px; font-size:8.5pt;">
                    <span class="sign-equal sign-total">(=)</span>
                    <strong>Diferencia {{ $difFinal == 0 ? '✓ Cuadrado' : '⚠ Pendiente' }}</strong>
                </td>
                <td class="amt" style="font-size:8.5pt;">L {{ number_format($difFinal, 2) }}</td>
            </tr>
        </table>
    </div>
    @endif

    {{-- ══ FIRMAS ═════════════════════════════════════════════════ --}}
    <div class="signature-section">
        <div class="signature-block">
            <div style="height:36px;"></div>
            <div class="signature-line"></div>
            <div class="signature-label">Elaborado por</div>
            <div class="signature-sub">Nombre / Cargo / Fecha</div>
        </div>
        <div class="signature-block">
            <div style="height:36px;"></div>
            <div class="signature-line"></div>
            <div class="signature-label">Revisado por</div>
            <div class="signature-sub">Nombre / Cargo / Fecha</div>
        </div>
        <div class="signature-block">
            <div style="height:36px;"></div>
            <div class="signature-line"></div>
            <div class="signature-label">Autorizado por</div>
            <div class="signature-sub">Nombre / Cargo / Fecha</div>
        </div>
    </div>

    {{-- ══ PIE ════════════════════════════════════════════════════ --}}
    <div class="report-footer">
        Distribuidora Hosana &nbsp;·&nbsp; Reporte de Manifiestos &nbsp;·&nbsp;
        No. {{ $reportNumber }} &nbsp;·&nbsp; Generado: {{ $generatedAt }}<br>
        Documento generado por sistema — Para uso interno.
    </div>

</div>
</div>

<script>
// Ajusta el padding-top del contenedor según la altura real del toolbar
(function () {
    var toolbar = document.getElementById('toolbar');
    var container = document.getElementById('report-container');
    if (toolbar && container) {
        container.style.paddingTop = (toolbar.offsetHeight + 16) + 'px';
    }
    window.addEventListener('resize', function () {
        if (toolbar && container) {
            container.style.paddingTop = (toolbar.offsetHeight + 16) + 'px';
        }
    });
})();
</script>
</body>
</html>
