<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<title>Reporte de Ventas por Bodega — Distribuidora Hosana</title>
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
    margin-bottom: 14px;
}
.report-header table { width: 100%; border-collapse: collapse; }
.report-header .left  { vertical-align: top; width: 70%; }
.report-header .right { vertical-align: top; text-align: right; width: 30%; }
.report-header .company { font-size: 13pt; font-weight: bold; color: #1e3a5f; }
.report-header .title   { font-size: 10.5pt; font-weight: bold; margin: 2px 0 1px 0; }
.report-header .meta    { font-size: 7.5pt; color: #555; margin-top: 3px; line-height: 1.6; }
.report-header .report-num {
    font-size: 7.5pt; color: #1e3a5f; font-weight: bold;
}
.report-header .report-meta-right {
    font-size: 7pt; color: #666; margin-top: 3px; line-height: 1.6;
}
.report-header .subtitle-badge {
    display: inline-block;
    background: #0ea5e9;
    color: #fff;
    font-size: 7pt;
    font-weight: bold;
    padding: 2px 8px;
    border-radius: 3px;
    margin-top: 4px;
    letter-spacing: 0.5px;
}

/* ══ TARJETAS DE RESUMEN ════════════════════════════════════════ */
.summary-section { margin-bottom: 14px; }
.summary-row {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
}
.summary-card {
    flex: 1;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    padding: 7px 10px;
    background: #f8fafc;
}
.summary-card.highlight { background: #eff6ff; border-color: #93c5fd; }
.summary-card.danger    { background: #fff7ed; border-color: #fed7aa; }
.summary-card.success   { background: #f0fdf4; border-color: #86efac; }
.summary-card .sc-label { font-size: 6.5pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; }
.summary-card .sc-value { font-size: 11pt; font-weight: bold; color: #111; margin-top: 1px; }
.summary-card .sc-sub   { font-size: 6.5pt; color: #9ca3af; margin-top: 1px; }
.summary-card.success .sc-value { color: #15803d; }
.summary-card.danger  .sc-value { color: #b45309; }

/* ══ FILTROS APLICADOS ══════════════════════════════════════════ */
.filters-bar {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 5px;
    padding: 5px 10px;
    font-size: 7pt;
    color: #475569;
    margin-bottom: 12px;
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: center;
}
.filters-bar strong { color: #1e3a5f; }

/* ══ TABLA PRINCIPAL ════════════════════════════════════════════ */
.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8pt;
    margin-bottom: 14px;
}
.data-table thead tr {
    background: #1e3a5f;
    color: #fff;
}
.data-table thead th {
    padding: 6px 7px;
    text-align: left;
    font-weight: bold;
    font-size: 7.5pt;
    white-space: nowrap;
}
.data-table thead th.num { text-align: right; }
.data-table tbody tr:nth-child(even) { background: #f8fafc; }
.data-table tbody tr:hover { background: #eff6ff; }
.data-table tbody td {
    padding: 6px 7px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}
.data-table tbody td.num { text-align: right; font-variant-numeric: tabular-nums; }
.data-table tbody td.center { text-align: center; }

/* Fila de ranking */
.rank-badge {
    display: inline-block;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    text-align: center;
    line-height: 18px;
    font-size: 7.5pt;
    font-weight: bold;
    color: #fff;
}
.rank-1 { background: #b45309; }  /* oro   */
.rank-2 { background: #6b7280; }  /* plata */
.rank-3 { background: #92400e; }  /* bronce*/
.rank-other { background: #cbd5e1; color: #374151; }

/* Barra de progreso de devolución */
.dev-bar-wrap {
    background: #e5e7eb;
    border-radius: 3px;
    height: 7px;
    width: 80px;
    display: inline-block;
    vertical-align: middle;
    margin-left: 5px;
}
.dev-bar-fill {
    height: 7px;
    border-radius: 3px;
    background: #f59e0b;
}
.dev-bar-fill.high  { background: #ef4444; }   /* >15% */
.dev-bar-fill.low   { background: #22c55e; }   /* <5%  */

/* Colores de montos */
.amount-invoices { color: #1e40af; font-weight: bold; }
.amount-returns  { color: #b45309; }
.amount-neto     { color: #15803d; font-weight: bold; }

/* Fila de total */
.data-table tfoot tr {
    background: #1e3a5f;
    color: #fff;
    font-weight: bold;
}
.data-table tfoot td {
    padding: 7px 7px;
    font-size: 8.5pt;
}
.data-table tfoot td.num { text-align: right; }

/* ══ BLOQUE DE CONCILIACIÓN ═════════════════════════════════════ */
.conciliation {
    margin-top: 14px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    overflow: hidden;
    width: 260px;
    float: right;
    font-size: 8pt;
}
.conciliation .con-title {
    background: #1e3a5f;
    color: #fff;
    padding: 5px 10px;
    font-weight: bold;
    font-size: 7.5pt;
    letter-spacing: 0.3px;
}
.conciliation table { width: 100%; border-collapse: collapse; }
.conciliation td {
    padding: 4px 10px;
    border-bottom: 1px solid #f0f0f0;
}
.conciliation td.label { color: #374151; }
.conciliation td.val   { text-align: right; font-weight: bold; }
.conciliation .con-total td { background: #f0fdf4; font-weight: bold; color: #15803d; border-top: 2px solid #86efac; }

/* ══ FIRMAS ══════════════════════════════════════════════════════ */
.signatures {
    clear: both;
    margin-top: 30px;
    display: flex;
    gap: 30px;
}
.sig-box {
    flex: 1;
    border-top: 1px solid #9ca3af;
    padding-top: 5px;
    text-align: center;
    font-size: 7.5pt;
    color: #374151;
}
.sig-box .sig-name  { font-weight: bold; font-size: 8pt; }
.sig-box .sig-title { color: #6b7280; font-size: 7pt; margin-top: 1px; }

/* ══ NOTA AL PIE ════════════════════════════════════════════════ */
.footnote {
    margin-top: 18px;
    font-size: 7pt;
    color: #9ca3af;
    border-top: 1px solid #e5e7eb;
    padding-top: 6px;
    clear: both;
}

/* ══ IMPRESIÓN ══════════════════════════════════════════════════ */
@media print {
    #toolbar { display: none !important; }
    body { background: #fff; }
    #report-container { padding: 0; }
    .report-page { box-shadow: none; margin: 0; width: 100%; }
}
</style>
</head>
<body>

{{-- ═══ TOOLBAR ════════════════════════════════════════════════════ --}}
<div id="toolbar">
    <div class="info">
        <strong>Reporte de Ventas por Bodega</strong>
        <span>{{ $supplier->name ?? 'Distribuidora Hosana' }} · Generado {{ $generatedAt }}</span>
    </div>
    <button class="btn-print" onclick="window.print()">🖨 Imprimir</button>
</div>

<div id="report-container">
<div class="report-page">

    {{-- ═══ ENCABEZADO ════════════════════════════════════════════════ --}}
    <div class="report-header">
        <table>
            <tr>
                <td class="left">
                    <div class="company">{{ $supplier->name ?? 'Distribuidora Hosana' }}</div>
                    @if($supplier && $supplier->rtn)
                        <div style="font-size:7.5pt;color:#555;">RTN: {{ $supplier->rtn }}</div>
                    @endif
                    <div class="title">Reporte de Ventas por Bodega</div>
                    <div class="meta">
                        Período: <strong>
                            @if(!empty($filters['date_from']) && !empty($filters['date_to']))
                                {{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }}
                                @if($filters['date_from'] !== $filters['date_to'])
                                    — {{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }}
                                @endif
                            @else
                                Todos los períodos
                            @endif
                        </strong><br>
                        Filtrado por: <strong>{{ $dateFieldLabel }}</strong><br>
                        Estado: <strong>
                            @php
                                $statusMap = ['imported' => 'Importados', 'closed' => 'Cerrados'];
                            @endphp
                            {{ $statusMap[$filters['status'] ?? ''] ?? 'Todos' }}
                        </strong>
                    </div>
                    <span class="subtitle-badge">CONSOLIDADO POR BODEGA</span>
                </td>
                <td class="right">
                    <div class="report-num">{{ $reportNumber }}</div>
                    <div class="report-meta-right">
                        Generado: {{ $generatedAt }}<br>
                        Bodegas: <strong>{{ $totals['warehouses_count'] }}</strong><br>
                        Manifiestos: <strong>{{ $totals['manifests_count'] }}</strong>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- ═══ TARJETAS DE RESUMEN ════════════════════════════════════════ --}}
    <div class="summary-section">
        <div class="summary-row">
            <div class="summary-card highlight">
                <div class="sc-label">Total Facturado</div>
                <div class="sc-value">HNL {{ number_format($totals['total_invoices'], 2) }}</div>
                <div class="sc-sub">{{ number_format($totals['invoices_count']) }} facturas · {{ $totals['warehouses_count'] }} bodegas</div>
            </div>
            <div class="summary-card danger">
                <div class="sc-label">Total Devoluciones</div>
                <div class="sc-value">HNL {{ number_format($totals['total_returns'], 2) }}</div>
                <div class="sc-sub">
                    {{ number_format($totals['returns_count']) }} devoluciones ·
                    @if($totals['total_invoices'] > 0)
                        {{ number_format(($totals['total_returns'] / $totals['total_invoices']) * 100, 1) }}% del total
                    @else
                        0%
                    @endif
                </div>
            </div>
            <div class="summary-card success">
                <div class="sc-label">Venta Neta (a depositar)</div>
                <div class="sc-value">HNL {{ number_format($totals['total_neto'], 2) }}</div>
                <div class="sc-sub">{{ number_format($totals['clients_count']) }} clientes únicos</div>
            </div>
        </div>
    </div>

    {{-- ═══ TABLA PRINCIPAL ════════════════════════════════════════════ --}}
    <table class="data-table">
        <thead>
            <tr>
                <th style="width:28px;">#</th>
                <th>Bodega</th>
                <th class="num">Manifiestos</th>
                <th class="num">Facturas</th>
                <th class="num">Clientes</th>
                <th class="num">Total Facturado</th>
                <th class="num">Devoluciones</th>
                <th class="num" style="width:80px;">% Dev.</th>
                <th class="num">Venta Neta</th>
                <th class="num">% del Total</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $i => $row)
                @php
                    $rank       = $i + 1;
                    $devPct     = $row->total_invoices > 0
                                    ? ($row->total_returns / $row->total_invoices) * 100
                                    : 0;
                    $netoPct    = $totals['total_neto'] > 0
                                    ? ($row->total_neto / $totals['total_neto']) * 100
                                    : 0;
                    $barClass   = $devPct >= 15 ? 'high' : ($devPct <= 5 ? 'low' : '');
                    $rankClass  = match($rank) { 1 => 'rank-1', 2 => 'rank-2', 3 => 'rank-3', default => 'rank-other' };
                @endphp
                <tr>
                    <td class="center">
                        <span class="rank-badge {{ $rankClass }}">{{ $rank }}</span>
                    </td>
                    <td>
                        <strong>{{ $row->warehouse_code }}</strong>
                        <span style="color:#6b7280;"> · {{ $row->warehouse_name }}</span>
                        @if($row->warehouse_city)
                            <br><span style="font-size:6.5pt;color:#9ca3af;">{{ $row->warehouse_city }}</span>
                        @endif
                    </td>
                    <td class="num">{{ number_format($row->manifests_count) }}</td>
                    <td class="num">{{ number_format($row->invoices_count) }}</td>
                    <td class="num">{{ number_format($row->clients_count) }}</td>
                    <td class="num amount-invoices">{{ number_format($row->total_invoices, 2) }}</td>
                    <td class="num amount-returns">{{ number_format($row->total_returns, 2) }}</td>
                    <td class="num">
                        {{ number_format($devPct, 1) }}%
                        <div class="dev-bar-wrap">
                            <div class="dev-bar-fill {{ $barClass }}" style="width:{{ min(100, $devPct) }}%;"></div>
                        </div>
                    </td>
                    <td class="num amount-neto">{{ number_format($row->total_neto, 2) }}</td>
                    <td class="num" style="color:#6b7280;">{{ number_format($netoPct, 1) }}%</td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" style="text-align:center;padding:20px;color:#9ca3af;">
                        No se encontraron datos con los filtros seleccionados.
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">TOTALES ({{ $totals['warehouses_count'] }} bodegas · {{ $totals['manifests_count'] }} manifiestos)</td>
                <td class="num">{{ number_format($totals['invoices_count']) }}</td>
                <td class="num">{{ number_format($totals['clients_count']) }}</td>
                <td class="num">{{ number_format($totals['total_invoices'], 2) }}</td>
                <td class="num">{{ number_format($totals['total_returns'], 2) }}</td>
                <td class="num">
                    @if($totals['total_invoices'] > 0)
                        {{ number_format(($totals['total_returns'] / $totals['total_invoices']) * 100, 1) }}%
                    @else
                        0%
                    @endif
                </td>
                <td class="num">{{ number_format($totals['total_neto'], 2) }}</td>
                <td class="num">100%</td>
            </tr>
        </tfoot>
    </table>

    {{-- ═══ BLOQUE DE CONCILIACIÓN ═════════════════════════════════════ --}}
    <div class="conciliation">
        <div class="con-title">Resumen de Conciliación</div>
        <table>
            <tr>
                <td class="label">Total Facturado</td>
                <td class="val">HNL {{ number_format($totals['total_invoices'], 2) }}</td>
            </tr>
            <tr>
                <td class="label">(–) Devoluciones aprobadas</td>
                <td class="val" style="color:#b45309;">({{ number_format($totals['total_returns'], 2) }})</td>
            </tr>
            <tr class="con-total">
                <td class="label">(=) Venta Neta</td>
                <td class="val">HNL {{ number_format($totals['total_neto'], 2) }}</td>
            </tr>
        </table>
    </div>

    {{-- ═══ FIRMAS ══════════════════════════════════════════════════════ --}}
    <div class="signatures">
        <div class="sig-box">
            <div class="sig-name">_______________________________</div>
            <div class="sig-title">Gerencia Comercial</div>
        </div>
        <div class="sig-box">
            <div class="sig-name">_______________________________</div>
            <div class="sig-title">Administración / Contabilidad</div>
        </div>
        <div class="sig-box">
            <div class="sig-name">_______________________________</div>
            <div class="sig-title">Revisado por</div>
        </div>
    </div>

    {{-- ═══ NOTA AL PIE ════════════════════════════════════════════════ --}}
    <div class="footnote">
        <strong>Nota:</strong> Los totales por bodega se calculan sobre las facturas con bodega asignada y devoluciones aprobadas.
        Las devoluciones pendientes o rechazadas no se incluyen en la venta neta.
        El porcentaje de devolución (% Dev.) se calcula como: Devoluciones / Total Facturado × 100.
        Barras verdes = % Dev. &lt; 5% · Amarillas = 5–15% · Rojas = &gt; 15%.
    </div>

</div>{{-- .report-page --}}
</div>{{-- #report-container --}}

<script>
// Ajustar padding-top al alto real del toolbar (evita que corte el contenido)
(function () {
    var toolbar   = document.getElementById('toolbar');
    var container = document.getElementById('report-container');
    function adjust() {
        if (toolbar && container) {
            container.style.paddingTop = (toolbar.offsetHeight + 16) + 'px';
        }
    }
    adjust();
    window.addEventListener('resize', adjust);
})();
</script>
</body>
</html>
