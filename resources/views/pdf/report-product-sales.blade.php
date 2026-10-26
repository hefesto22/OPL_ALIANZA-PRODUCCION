@php
    $totals = $report['totals'];
    $from = \Illuminate\Support\Carbon::parse($report['filters']['date_from'])->format('d/m/Y');
    $to = \Illuminate\Support\Carbon::parse($report['filters']['date_to'])->format('d/m/Y');
    $warehouseLabel = collect($report['warehouses'])
        ->map(fn (array $warehouse): string => $warehouse['code'].' — '.$warehouse['name'])
        ->implode(' · ');
    $warehouseCodes = collect($report['warehouses'])->pluck('code')->implode(', ');
    $recon = $report['transfers']['reconciliation'] ?? null;
    // "3,816 cj + 25 u" en dos piezas que nunca se parten por dentro: si la
    // columna no alcanza, la línea baja entera en el "+" en vez de recortarse.
    $qty = fn (string $label): string => '<span class="nw">'
        .str_replace(' + ', '</span> <span class="nw">+ ', e($label))
        .'</span>';
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

/* ── Recuadro de traslados: de lo que Jaremar facturó al facturado del reporte ── */
.recon { margin-bottom: 10px; padding: 6px 10px 5px; border: 1px solid #bfdbfe; border-left: 4px solid #2563eb; border-radius: 6px; background: #eff6ff; page-break-inside: avoid; }
.recon-title { margin-bottom: 3px; font-size: 7.5pt; font-weight: bold; color: #1e3a8a; text-transform: uppercase; letter-spacing: .4px; }
.recon table { width: 100%; border-collapse: collapse; font-size: 7.5pt; font-variant-numeric: tabular-nums; }
.recon td { padding: 2px 4px; vertical-align: top; color: #1e293b; }
.recon td.op { width: 10px; font-size: 9pt; font-weight: bold; text-align: center; color: #1e3a8a; }
.recon td.n { width: 1%; text-align: right; white-space: nowrap; }
.recon tr.out td.n { color: #b91c1c; font-weight: bold; }
.recon tr.in td.n { color: #15803d; font-weight: bold; }
.recon tr.result td { border-top: 1px solid #93c5fd; font-weight: bold; color: #1e3a8a; }
.recon .inv { display: block; margin-top: 1px; font-size: 6.5pt; color: #475569; }
.recon-foot { margin-top: 3px; font-size: 6.5pt; color: #475569; }

/* ── Table ────────────────────────────────────────────────────── */
/* Anchos automáticos: cada columna toma lo que necesita su número más largo y
   la descripción se acomoda en el resto. Nada se recorta con "…". */
table.data { width: 100%; border-collapse: collapse; font-size: 7pt; table-layout: auto; }
.nw { white-space: nowrap; }
table.data thead tr { background: linear-gradient(135deg, #1e3a5f 0%, #2d5a8e 100%); color: #fff; }
table.data thead th { padding: 4px 3px; text-align: right; font-size: 6pt; text-transform: uppercase; white-space: nowrap; }
table.data thead th.l { text-align: left; }
table.data thead th.c { text-align: center; }
table.data tbody tr:nth-child(even) { background: #f8fafc; }
table.data tbody td { padding: 3px; border-bottom: 1px solid #e5e7eb; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
table.data tbody td.l { text-align: left; white-space: normal; }
table.data td.desc { min-width: 150px; }
table.data td.q { white-space: normal; }
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
table.data tfoot td.q { white-space: normal; }
table.data tfoot td.l { text-align: left; }

/* ── Traslados entre bodegas ─────────────────────────────────── */
.transfers { margin-top: 14px; }
.transfers-head { display: flex; justify-content: space-between; align-items: baseline; border-bottom: 2px solid #1e3a5f; padding-bottom: 4px; margin-bottom: 6px; }
.transfers-title { font-size: 10pt; font-weight: bold; color: #1e3a5f; }
.transfers-sub { font-size: 7pt; color: #64748b; }
.transfers-chips span { display: inline-block; margin-left: 6px; padding: 2px 8px; border-radius: 10px; font-size: 7.5pt; font-weight: bold; }
.chip-in { background: #e0f2fe; color: #075985; }
.chip-out { background: #fef3c7; color: #92400e; }
.transfers-group { margin-top: 8px; font-size: 7pt; font-weight: bold; color: #475569; text-transform: uppercase; letter-spacing: .5px; }
.transfer { margin-top: 5px; border: 1px solid #cbd5e1; border-radius: 6px; }
.transfer-head, .move { page-break-after: avoid; break-after: avoid; }
table.returns tr { page-break-inside: avoid; break-inside: avoid; }
table.returns thead { display: table-header-group; }
.transfer-head { display: flex; flex-wrap: wrap; gap: 4px 10px; align-items: center; padding: 5px 8px; background: #f1f5f9; font-size: 7.5pt; }
.route { background: #1e3a5f; color: #fff; padding: 1px 7px; border-radius: 4px; font-weight: bold; }
.transfer-total { margin-left: auto; font-weight: bold; }
.move { padding: 3px 8px; font-size: 7pt; color: #334155; border-top: 1px solid #e2e8f0; }
.move em { color: #475569; }
table.returns { width: 100%; border-collapse: collapse; font-size: 6.5pt; }
table.returns th { background: #e2e8f0; color: #334155; text-align: left; padding: 3px 4px; font-size: 6pt; text-transform: uppercase; }
table.returns th.r, table.returns td.r { text-align: right; }
table.returns td { padding: 3px 4px; border-top: 1px solid #e2e8f0; vertical-align: top; }
table.returns tr.void td { color: #94a3b8; }
table.returns td.who { font-size: 6pt; color: #334155; }
table.returns .code { font-family: 'Consolas', 'Courier New', monospace; color: #64748b; }
table.returns tfoot td { font-weight: bold; border-top: 2px solid #94a3b8; background: #f1f5f9; padding: 4px; }
.bonus-tag { color: #15803d; font-weight: bold; }
.subhead { page-break-after: avoid; break-after: avoid; padding: 4px 8px 2px; font-size: 6.5pt; font-weight: bold; color: #475569; text-transform: uppercase; border-top: 1px solid #e2e8f0; }
.badge-void { color: #b91c1c; font-weight: bold; }

.notes { margin-top: 10px; font-size: 7pt; color: #475569; line-height: 1.55; border: 1px solid #e2e8f0; border-radius: 6px; padding: 7px 10px; background: #f8fafc; }
.notes strong { color: #1e3a5f; }
.report-footer { margin-top: 12px; border-top: 2px solid #e2e8f0; padding-top: 6px; font-size: 7pt; color: #94a3b8; text-align: center; }

/* ── Paginación ───────────────────────────────────────────────── */
table.data thead { display: table-header-group; }
table.data tfoot { display: table-row-group; }
table.data tbody tr { page-break-inside: avoid; break-inside: avoid; }

/* ── Print ────────────────────────────────────────────────────── */
@media print {
    /* Sin tamaño fijo: sale en Carta o A4 según la impresora, siempre horizontal. */
    @page { size: landscape; margin: 8mm; }
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
            <div class="sub">Vendido {{ $totals['net']['label'] }}</div>
        </div>
    </div>

    @if ($recon !== null && $recon['applies'])
    <div class="recon">
        <div class="recon-title">Facturas que se movieron de bodega</div>
        <table>
            <tr>
                <td class="op"></td>
                <td>Jaremar le facturó a {{ $warehouseCodes }}</td>
                <td class="n">{{ number_format($recon['billed_by_jaremar']['count']) }} facturas</td>
                <td class="n">L {{ number_format($recon['billed_by_jaremar']['total'], 2) }}</td>
            </tr>
            @foreach ($recon['left']['groups'] as $group)
            <tr class="out">
                <td class="op">−</td>
                <td>
                    {{ $group['count'] === 1 ? 'Factura que se envió' : 'Facturas que se enviaron' }} a <strong>{{ $group['from'] }}</strong>
                    pero después {{ $group['count'] === 1 ? 'se movió' : 'se movieron' }} a <strong>{{ $group['to'] }}</strong>:
                    ese dinero no cuenta en este reporte, cuenta en {{ $group['to'] }} ({{ $group['quantity'] }})
                    <span class="inv">{{ implode(' · ', $group['invoices']) }}</span>
                </td>
                <td class="n">{{ $group['count'] }} {{ $group['count'] === 1 ? 'factura' : 'facturas' }}</td>
                <td class="n">− L {{ number_format($group['total'], 2) }}</td>
            </tr>
            @endforeach
            @foreach ($recon['arrived']['groups'] as $group)
            <tr class="in">
                <td class="op">+</td>
                <td>
                    {{ $group['count'] === 1 ? 'Factura que se envió' : 'Facturas que se enviaron' }} a <strong>{{ $group['from'] }}</strong>
                    pero después {{ $group['count'] === 1 ? 'se movió' : 'se movieron' }} a <strong>{{ $group['to'] }}</strong>:
                    ese dinero sí cuenta en este reporte ({{ $group['quantity'] }})
                    <span class="inv">{{ implode(' · ', $group['invoices']) }}</span>
                </td>
                <td class="n">{{ $group['count'] }} {{ $group['count'] === 1 ? 'factura' : 'facturas' }}</td>
                <td class="n">+ L {{ number_format($group['total'], 2) }}</td>
            </tr>
            @endforeach
            <tr class="result">
                <td class="op">=</td>
                <td>Facturado en este reporte (la tarjeta "Facturado")</td>
                <td class="n">{{ number_format($recon['in_report']['count']) }} facturas</td>
                <td class="n">L {{ number_format($recon['in_report']['total'], 2) }}</td>
            </tr>
        </table>
        <div class="recon-foot">
            Lo devuelto de esas facturas también cuenta en la bodega donde quedaron.
            El detalle de cada una (productos y quién devolvió) está abajo, en "Facturas trasladadas entre bodegas".
        </div>
    </div>
    @endif

    @if (count($report['rows']) > 0)
    <table class="data">
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
                <td class="l desc">{{ $row['description'] }}</td>
                <td class="c">{{ $row['unit_sale'] ?? '—' }}</td>
                <td class="q sep">{!! $qty($row['entered']['label']) !!}</td>
                <td class="q {{ $row['bonus_units'] > 0 ? 'bonus' : 'zero' }}">{!! $qty($row['bonus']['label']) !!}</td>
                <td class="q {{ $row['returned_units'] > 0 ? 'ret' : 'zero' }}">{!! $qty($row['returned']['label']) !!}</td>
                <td class="q net">{!! $qty($row['net']['label']) !!}</td>
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
                <td class="q">{!! $qty($totals['entered']['label']) !!}</td>
                <td class="q">{!! $qty($totals['bonus']['label']) !!}</td>
                <td class="q">{!! $qty($totals['returned']['label']) !!}</td>
                <td class="q">{!! $qty($totals['net']['label']) !!}</td>
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

    @php
        $transfers = $report['transfers'];
        $transferGroups = [
            ['key' => 'received', 'label' => 'Llegaron por traslado (cuentan en este reporte)'],
            ['key' => 'sent', 'label' => 'Salieron a otra bodega (no cuentan en este reporte)'],
        ];
    @endphp
    <div class="transfers">
        <div class="transfers-head">
            <div>
                <span class="transfers-title">Facturas trasladadas entre bodegas</span>
                <span class="transfers-sub">&nbsp;· Una factura trasladada cuenta como venta de la bodega donde quedó.</span>
            </div>
            <div class="transfers-chips">
                <span class="chip-in">Llegaron: {{ $transfers['received_count'] }} · L {{ number_format($transfers['received_total'], 2) }}</span>
                <span class="chip-out">Salieron: {{ $transfers['sent_count'] }} · L {{ number_format($transfers['sent_total'], 2) }}</span>
            </div>
        </div>

        @if ($transfers['received_count'] === 0 && $transfers['sent_count'] === 0)
            <div class="transfers-sub">Ninguna factura de este período cambió de bodega.</div>
        @endif

        @foreach ($transferGroups as $group)
            @if (count($transfers[$group['key']]) > 0)
                <div class="transfers-group">{{ $group['label'] }}</div>
                @foreach ($transfers[$group['key']] as $invoice)
                <div class="transfer">
                    <div class="transfer-head">
                        <span class="route">{{ $invoice['chain'] }}</span>
                        <strong>{{ $invoice['invoice_number'] }}</strong>
                        <span>{{ $invoice['client'] }} · {{ $invoice['invoice_date'] }} · Manifiesto {{ $invoice['manifest_number'] }}</span>
                        <span class="transfer-total">
                            L {{ number_format($invoice['total'], 2) }}
                            @if ($invoice['returned_total'] > 0)
                                <span style="color:#b91c1c;">(devuelto L {{ number_format($invoice['returned_total'], 2) }})</span>
                            @endif
                        </span>
                    </div>
                    @foreach ($invoice['moves'] as $move)
                    <div class="move">
                        Trasladada de <strong>{{ $move['from'] }}</strong> a <strong>{{ $move['to'] }}</strong> el {{ $move['at'] }}
                        por <strong>{{ $move['by'] }}</strong> — <em>“{{ $move['reason'] }}”</em>
                    </div>
                    @endforeach
                    <div class="subhead">Productos de esta factura</div>
                    <table class="returns">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Producto</th>
                                <th class="r">Cantidad</th>
                                <th class="r">Devuelto</th>
                                <th>Quién devolvió</th>
                                <th class="r">Neto</th>
                                <th class="r">Monto L</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice['products'] as $product)
                            <tr>
                                <td class="code">{{ $product['product_id'] }}</td>
                                <td>{{ $product['description'] }}@if ($product['bonus']) <span class="bonus-tag">(bonif.)</span>@endif</td>
                                <td class="r">{!! $qty($product['quantity']) !!}</td>
                                <td class="r" style="{{ $product['returned_units'] > 0 ? 'color:#b91c1c;' : 'color:#cbd5e1;' }}">{!! $qty($product['returned']) !!}</td>
                                <td class="who">
                                    @forelse ($product['returned_by'] as $who)
                                        <strong>{{ $who['user'] }}</strong> ({{ $who['user_warehouses'] }}) — {{ $who['quantity'] }} · en {{ $who['registered_in'] }} · {{ $who['registered_at'] }} · {{ mb_strtolower($who['timing']) }}@if (! $loop->last)<br>@endif
                                    @empty
                                        —
                                    @endforelse
                                </td>
                                <td class="r"><strong>{!! $qty($product['net']) !!}</strong></td>
                                <td class="r">{{ number_format($product['amount'], 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if (count($invoice['returns']) === 0)
                    <div class="move">Sin devoluciones.</div>
                    @else
                    <div class="subhead">Devoluciones de esta factura</div>
                    <table class="returns">
                        <thead>
                            <tr>
                                <th>Registrada</th>
                                <th>Registró</th>
                                <th>Bodega del usuario</th>
                                <th>Factura estaba en</th>
                                <th>Momento</th>
                                <th>Tipo / estado</th>
                                <th>Productos</th>
                                <th class="r">Monto L</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice['returns'] as $return)
                            <tr class="{{ $return['counts'] ? '' : 'void' }}">
                                <td>{{ $return['registered_at'] }}</td>
                                <td>{{ $return['user'] }}</td>
                                <td>{{ $return['user_warehouses'] }}</td>
                                <td><strong>{{ $return['registered_in'] }}</strong></td>
                                <td>{{ $return['timing'] }}</td>
                                <td>{{ $return['type'] }} · <span class="{{ $return['counts'] ? '' : 'badge-void' }}">{{ $return['status'] }}</span></td>
                                <td>
                                    @foreach ($return['lines'] as $line)
                                        {{ $line['description'] }} — <span class="nw">{{ $line['quantity'] }}</span>@if (! $loop->last)<br>@endif
                                    @endforeach
                                </td>
                                <td class="r">{{ number_format($return['total'], 2) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @endif
                </div>
                @endforeach
            @endif
        @endforeach

        @if (count($transfers['products_summary']) > 0)
        <div class="transfers-group">Resumen de productos trasladados</div>
        <div class="transfer">
            <table class="returns">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Producto</th>
                        <th class="r">Llegaron</th>
                        <th class="r">L llegaron</th>
                        <th class="r">Salieron</th>
                        <th class="r">L salieron</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($transfers['products_summary'] as $product)
                    <tr>
                        <td class="code">{{ $product['product_id'] }}</td>
                        <td>{{ $product['description'] }}</td>
                        <td class="r">{!! $qty($product['received']['label']) !!}</td>
                        <td class="r">{{ number_format($product['received_amount'], 2) }}</td>
                        <td class="r">{!! $qty($product['sent']['label']) !!}</td>
                        <td class="r">{{ number_format($product['sent_amount'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">TOTAL — {{ count($transfers['products_summary']) }} productos</td>
                        <td class="r">{!! $qty($transfers['products_totals']['received']['label']) !!}</td>
                        <td class="r">{{ number_format($transfers['products_totals']['received_amount'], 2) }}</td>
                        <td class="r">{!! $qty($transfers['products_totals']['sent']['label']) !!}</td>
                        <td class="r">{{ number_format($transfers['products_totals']['sent_amount'], 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @endif
    </div>

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
