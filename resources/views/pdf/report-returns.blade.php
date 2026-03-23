<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<title>Reporte de Devoluciones{{ $manifest ? ' — Manifiesto #'.$manifest->number : '' }}</title>
<style>
/* ── Toolbar (solo en pantalla) ─────────────────────────────── */
#toolbar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
    background: #1e3a5f; color: #fff; padding: 10px 20px;
    display: flex; align-items: center; justify-content: space-between;
    font-family: Arial, sans-serif; font-size: 13px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}
#toolbar .info strong { font-size: 15px; display: block; }
#toolbar .info span   { font-size: 12px; color: #aac4e8; }
#toolbar .btn-print {
    background: #f59e0b; color: #000; border: none;
    padding: 8px 22px; border-radius: 6px;
    font-size: 14px; font-weight: bold; cursor: pointer;
}

/* ── Layout base ────────────────────────────────────────────── */
body { font-family: Arial, sans-serif; font-size: 9pt; color: #111; background: #e5e7eb; margin: 0; }
#report-container { margin-top: 60px; padding: 16px; }
.report-page {
    background: #fff; width: 270mm;
    margin: 0 auto 16px auto; padding: 11mm;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15); box-sizing: border-box;
}

/* ── Cabecera del reporte ───────────────────────────────────── */
.report-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    border-bottom: 3px solid #991b1b; padding-bottom: 8px; margin-bottom: 10px;
}
.report-header .left .company  { font-size: 14pt; font-weight: bold; color: #991b1b; }
.report-header .left .title    { font-size: 10.5pt; font-weight: bold; color: #111; margin: 2px 0; }
.report-header .left .subtitle { font-size: 8pt; color: #555; }
.report-header .right          { text-align: right; font-size: 7.5pt; color: #555; line-height: 1.6; }
.report-header .right strong   { color: #111; }

/* ── Recuadro del manifiesto (cuando viene filtrado) ────────── */
.manifest-info-box {
    border: 1px solid #fca5a5; border-radius: 4px;
    background: #fff5f5; padding: 7px 10px;
    margin-bottom: 10px; font-size: 7.5pt;
    display: flex; gap: 20px; flex-wrap: wrap;
}
.manifest-info-box .mif-item { display: flex; flex-direction: column; }
.manifest-info-box .mif-label { color: #666; font-size: 7pt; text-transform: uppercase; letter-spacing: .3px; }
.manifest-info-box .mif-value { font-weight: bold; color: #991b1b; font-size: 8.5pt; }

/* ── Período aplicado ───────────────────────────────────────── */
.period-pill {
    display: inline-block; background: #fef3c7; border: 1px solid #fde68a;
    color: #92400e; font-size: 7pt; font-weight: bold;
    padding: 2px 8px; border-radius: 10px; margin-bottom: 10px;
}

/* ── Cards de resumen ejecutivo ─────────────────────────────── */
.summary-box { display: flex; gap: 8px; margin-bottom: 12px; }
.summary-card {
    flex: 1; border: 1px solid #e5e7eb; border-radius: 5px;
    padding: 7px 8px; background: #f9fafb; text-align: center;
}
.summary-card .s-label { font-size: 6.5pt; color: #888; text-transform: uppercase; letter-spacing: .3px; }
.summary-card .s-value { font-size: 12pt; font-weight: bold; color: #111; margin-top: 2px; }
.summary-card .s-sub   { font-size: 6.5pt; color: #888; margin-top: 1px; }
.sc-approved { border-color: #86efac; }
.sc-approved .s-value  { color: #166534; }
.sc-pending  { border-color: #fde68a; }
.sc-pending  .s-value  { color: #92400e; }
.sc-rejected { border-color: #fca5a5; }
.sc-rejected .s-value  { color: #991b1b; }
.sc-amount   { border-color: #fca5a5; }
.sc-amount   .s-value  { color: #991b1b; font-size: 9.5pt; }
.sc-approved-amount { border-color: #86efac; }
.sc-approved-amount .s-value { color: #166534; font-size: 9.5pt; }

/* ── Encabezado de cada devolución ──────────────────────────── */
.return-block { margin-bottom: 14px; border: 1px solid #e5e7eb; border-radius: 4px; overflow: hidden; }
.return-header {
    background: #fee2e2; padding: 6px 10px;
    display: flex; justify-content: space-between; align-items: center;
}
.return-header .rh-left  { font-size: 8pt; font-weight: bold; color: #7f1d1d; }
.return-header .rh-right { font-size: 7.5pt; color: #7f1d1d; text-align: right; }
.return-header.approved  { background: #dcfce7; }
.return-header.approved .rh-left, .return-header.approved .rh-right { color: #14532d; }
.return-header.pending   { background: #fef3c7; }
.return-header.pending   .rh-left, .return-header.pending .rh-right  { color: #78350f; }
.return-header.rejected  { background: #fee2e2; }

/* Ficha de datos del cliente / factura */
.return-meta {
    display: flex; gap: 0; border-bottom: 1px solid #f3f4f6;
}
.return-meta .rm-col {
    flex: 1; padding: 5px 10px; font-size: 7pt;
    border-right: 1px solid #f3f4f6;
}
.return-meta .rm-col:last-child { border-right: none; }
.return-meta .rm-label { color: #888; text-transform: uppercase; font-size: 6pt; letter-spacing: .3px; }
.return-meta .rm-value { font-weight: bold; color: #111; margin-top: 1px; }

/* Tabla de líneas / productos */
table.lines { width: 100%; border-collapse: collapse; font-size: 7pt; }
table.lines thead tr { background: #374151; color: #fff; }
table.lines thead th { padding: 4px 6px; text-align: left; white-space: nowrap; }
table.lines thead th.r { text-align: right; }
table.lines tbody tr:nth-child(even) { background: #f9fafb; }
table.lines tbody td { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; }
table.lines tbody td.r { text-align: right; }
.lines-subtotal { background: #f3f4f6 !important; }
.lines-subtotal td { font-weight: bold; padding: 4px 6px; border-top: 1px solid #d1d5db; }

/* Nota de rechazo */
.rejection-note {
    background: #fef2f2; border-left: 3px solid #ef4444;
    padding: 5px 10px; font-size: 7.5pt; color: #7f1d1d;
    margin: 0;
}
.rejection-note strong { display: block; font-size: 7pt; text-transform: uppercase; margin-bottom: 2px; }

/* Firma del revisor */
.reviewer-note {
    background: #f0fdf4; border-left: 3px solid #22c55e;
    padding: 5px 10px; font-size: 7.5pt; color: #14532d;
    margin: 0;
}
.reviewer-note strong { display: block; font-size: 7pt; text-transform: uppercase; margin-bottom: 2px; }

/* ── Separador de grupo de manifiesto ───────────────────────── */
.group-header {
    background: #1e3a5f; color: #fff; padding: 5px 10px;
    font-size: 8pt; font-weight: bold; margin: 12px 0 4px 0;
    display: flex; justify-content: space-between;
    border-radius: 3px;
}

/* ── Tabla de totales final ─────────────────────────────────── */
.totals-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 8pt; }
.totals-table td { padding: 5px 8px; border: 1px solid #e5e7eb; }
.totals-table .tl-label { background: #f3f4f6; font-weight: bold; width: 60%; }
.totals-table .tl-value { text-align: right; font-weight: bold; }
.totals-table .grand-row td { background: #1e3a5f; color: #fff; font-size: 9pt; }

/* ── Pie de página ──────────────────────────────────────────── */
.report-footer {
    margin-top: 16px; border-top: 1px solid #e5e7eb;
    padding-top: 6px; font-size: 6.5pt; color: #aaa; text-align: center;
}
.page-break { page-break-before: always; }

/* ── Badges ─────────────────────────────────────────────────── */
.badge { display: inline-block; padding: 1px 6px; border-radius: 10px; font-size: 6.5pt; font-weight: bold; }
.badge-pending  { background: #fef3c7; color: #92400e; }
.badge-approved { background: #dcfce7; color: #166534; }
.badge-rejected { background: #fee2e2; color: #991b1b; }
.badge-total    { background: #ede9fe; color: #5b21b6; }
.badge-partial  { background: #e0f2fe; color: #0c4a6e; }

@media print {
    @page { size: 270mm auto portrait; margin: 8mm; }
    #toolbar { display: none !important; }
    body { background: none; }
    #report-container { margin-top: 0; padding: 0; }
    .report-page { width: 100%; margin: 0; box-shadow: none; padding: 0; }
    .return-block { page-break-inside: avoid; }
    .page-break { page-break-before: always; }
}
</style>
</head>
<body>

{{-- ── TOOLBAR (solo pantalla) ──────────────────────────────────────── --}}
<div id="toolbar">
    <div class="info">
        <strong>
            Reporte de Devoluciones
            @if($manifest) — Manifiesto #{{ $manifest->number }} @endif
        </strong>
        <span>
            @if(!empty($filters['date_from']) && ($filters['period'] ?? null) !== 'all')
                Período: {{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }}
                al {{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }}
                &nbsp;·&nbsp;
            @endif
            {{ $totals['count'] }} devoluciones
            &nbsp;·&nbsp; Total aprobado: L {{ number_format($totals['approved_amount'], 2) }}
        </span>
    </div>
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
</div>

<div id="report-container">
<div class="report-page">

    {{-- ── CABECERA ──────────────────────────────────────────────────── --}}
    <div class="report-header">
        <div class="left">
            <div class="company">Distribuidora Hosana</div>
            <div class="title">
                Reporte de Devoluciones
                @if($manifest) — Manifiesto #{{ $manifest->number }} @endif
            </div>
            <div class="subtitle">
                @if($manifest)
                    Proveedor: {{ $manifest->supplier?->name ?? '—' }}
                    &nbsp;·&nbsp; Fecha: {{ $manifest->date ? \Carbon\Carbon::parse($manifest->date)->format('d/m/Y') : '—' }}
                    &nbsp;·&nbsp; Estado: {{ match($manifest->status) { 'closed' => 'Cerrado', 'imported' => 'Importado', 'processing' => 'En Proceso', 'pending' => 'Pendiente', default => $manifest->status } }}
                @else
                    Reporte General de Devoluciones
                @endif
            </div>
        </div>
        <div class="right">
            <div>Generado: <strong>{{ $generatedAt }}</strong></div>
            <div>Por: <strong>{{ auth()->user()?->name ?? 'Sistema' }}</strong></div>
            @if($supplier)<div>{{ $supplier->name }}</div>@endif
        </div>
    </div>

    {{-- ── PERÍODO APLICADO ─────────────────────────────────────────── --}}
    @if(!empty($filters['period']) && $filters['period'] !== 'all')
    <div>
        <span class="period-pill">
            📅 Período:
            @switch($filters['period'])
                @case('today') Hoy ({{ now()->format('d/m/Y') }}) @break
                @case('week')  Esta semana ({{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }}) @break
                @case('month') Este mes ({{ now()->format('F Y') }}) @break
                @case('custom') Del {{ \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') }} @break
            @endswitch
        </span>
    </div>
    @endif

    {{-- ── RESUMEN EJECUTIVO ────────────────────────────────────────── --}}
    <div class="summary-box">
        <div class="summary-card">
            <div class="s-label">Total</div>
            <div class="s-value">{{ $totals['count'] }}</div>
            <div class="s-sub">devoluciones</div>
        </div>
        <div class="summary-card sc-approved">
            <div class="s-label">Aprobadas</div>
            <div class="s-value">{{ $totals['approved'] }}</div>
            <div class="s-sub">{{ $totals['count'] > 0 ? round($totals['approved'] / $totals['count'] * 100, 0) : 0 }}% del total</div>
        </div>
        <div class="summary-card sc-pending">
            <div class="s-label">Pendientes</div>
            <div class="s-value">{{ $totals['pending'] }}</div>
            <div class="s-sub">en revisión</div>
        </div>
        <div class="summary-card sc-rejected">
            <div class="s-label">Rechazadas</div>
            <div class="s-value">{{ $totals['rejected'] }}</div>
            <div class="s-sub">no procesadas</div>
        </div>
        <div class="summary-card sc-amount">
            <div class="s-label">Monto Total</div>
            <div class="s-value">L {{ number_format($totals['total'], 2) }}</div>
            <div class="s-sub">todas las devoluciones</div>
        </div>
        <div class="summary-card sc-approved-amount">
            <div class="s-label">Monto Aprobado</div>
            <div class="s-value">L {{ number_format($totals['approved_amount'], 2) }}</div>
            <div class="s-sub">impacto real en manifiesto</div>
        </div>
    </div>

    {{-- ── DEVOLUCIONES DETALLADAS ──────────────────────────────────── --}}
    @forelse($byManifest as $manifestNumber => $manifestData)

        {{-- Encabezado de grupo (solo si no hay manifiesto específico) --}}
        @if(!$manifest)
        <div class="group-header">
            <span>Manifiesto #{{ $manifestNumber }}</span>
            <span>{{ $manifestData['count'] }} devoluciones &nbsp;·&nbsp; L {{ number_format($manifestData['subtotal'], 2) }}</span>
        </div>
        @endif

        @foreach($manifestData['returns'] as $return)
        <div class="return-block">

            {{-- Encabezado con número y estado --}}
            <div class="return-header {{ $return->status }}">
                <div class="rh-left">
                    DEV-{{ str_pad($return->id, 4, '0', STR_PAD_LEFT) }}
                    &nbsp;·&nbsp;
                    <span class="badge badge-{{ $return->status }}">
                        {{ match($return->status) { 'pending' => 'Pendiente', 'approved' => 'Aprobada', 'rejected' => 'Rechazada', default => $return->status } }}
                    </span>
                    &nbsp;·&nbsp;
                    <span class="badge badge-{{ $return->type }}">
                        {{ $return->type === 'total' ? 'Devolución Total' : 'Devolución Parcial' }}
                    </span>
                </div>
                <div class="rh-right">
                    <strong>L {{ number_format($return->total, 2) }}</strong>
                    &nbsp;·&nbsp; Fecha: {{ $return->return_date?->format('d/m/Y') ?? '—' }}
                </div>
            </div>

            {{-- Ficha de datos: factura, cliente, bodega, motivo --}}
            <div class="return-meta">
                <div class="rm-col">
                    <div class="rm-label">Factura</div>
                    <div class="rm-value">{{ $return->invoice?->invoice_number ?? '—' }}</div>
                </div>
                <div class="rm-col">
                    <div class="rm-label">Fecha Factura</div>
                    <div class="rm-value">{{ $return->invoice?->invoice_date ? \Carbon\Carbon::parse($return->invoice->invoice_date)->format('d/m/Y') : '—' }}</div>
                </div>
                <div class="rm-col">
                    <div class="rm-label">Cód. Cliente</div>
                    <div class="rm-value">{{ $return->client_id ?? '—' }}</div>
                </div>
                <div class="rm-col" style="flex:2">
                    <div class="rm-label">Nombre del Cliente</div>
                    <div class="rm-value">{{ $return->client_name ?? '—' }}</div>
                </div>
                <div class="rm-col">
                    <div class="rm-label">Bodega</div>
                    <div class="rm-value">{{ $return->warehouse?->code ?? '—' }}</div>
                </div>
                <div class="rm-col" style="flex:2">
                    <div class="rm-label">Motivo de Devolución</div>
                    <div class="rm-value">
                        [{{ $return->returnReason?->code ?? '?' }}]
                        {{ $return->returnReason?->description ?? '—' }}
                    </div>
                </div>
            </div>

            {{-- Segunda fila de datos --}}
            <div class="return-meta" style="border-bottom:none;">
                <div class="rm-col">
                    <div class="rm-label">ID Hozana</div>
                    <div class="rm-value">DEV-{{ str_pad($return->id, 4, '0', STR_PAD_LEFT) }}</div>
                </div>
                <div class="rm-col">
                    <div class="rm-label">Fecha Procesada</div>
                    <div class="rm-value">
                        {{ $return->processed_date ? $return->processed_date->format('d/m/Y') : '—' }}
                        {{ $return->processed_time ? ' ' . \Carbon\Carbon::parse($return->processed_time)->format('H:i') : '' }}
                    </div>
                </div>
                <div class="rm-col">
                    <div class="rm-label">Registrada por</div>
                    <div class="rm-value">{{ $return->createdBy?->name ?? '—' }}</div>
                </div>
                <div class="rm-col">
                    <div class="rm-label">Revisada por</div>
                    <div class="rm-value">{{ $return->reviewedBy?->name ?? $return->createdBy?->name ?? '—' }}</div>
                </div>
                <div class="rm-col">
                    <div class="rm-label">Fecha Revisión</div>
                    <div class="rm-value">
                        @php
                            $reviewDate = $return->reviewed_at ?? $return->return_date;
                        @endphp
                        {{ $reviewDate ? \Carbon\Carbon::parse($reviewDate)->endOfDay()->format('d/m/Y H:i') : '—' }}
                    </div>
                </div>
                <div class="rm-col" style="flex:2">
                    <div class="rm-label">RTN Cliente</div>
                    <div class="rm-value">{{ $return->invoice?->client_rtn ?? '—' }}</div>
                </div>
            </div>

            {{-- Tabla de líneas (productos devueltos) --}}
            @if($return->lines->isNotEmpty())
            <table class="lines">
                <thead>
                    <tr>
                        <th style="width:40px">#</th>
                        <th style="width:90px">Cód. Producto</th>
                        <th>Descripción del Producto</th>
                        <th class="r" style="width:70px">Cant. Devuelta</th>
                        <th class="r" style="width:90px">Subtotal (HNL)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($return->lines as $line)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $line->product_id ?? '—' }}</strong></td>
                        <td>{{ $line->product_description ?? '—' }}</td>
                        <td class="r">{{ number_format($line->quantity, 2) }}</td>
                        <td class="r">L {{ number_format($line->line_total, 2) }}</td>
                    </tr>
                    @endforeach
                    <tr class="lines-subtotal">
                        <td colspan="3">Total de la devolución</td>
                        <td class="r">{{ number_format($return->lines->sum('quantity'), 2) }} uds.</td>
                        <td class="r">L {{ number_format($return->total, 2) }}</td>
                    </tr>
                </tbody>
            </table>
            @else
            <div style="padding:6px 10px; font-size:7pt; color:#888; font-style:italic;">
                Sin detalle de líneas disponible para esta devolución.
            </div>
            @endif

            {{-- Nota de rechazo --}}
            @if($return->isRejected() && $return->rejection_reason)
            <div class="rejection-note">
                <strong>Motivo de Rechazo</strong>
                {{ $return->rejection_reason }}
            </div>
            @endif

            {{-- Nota del revisor (si aprobada) --}}
            @if($return->isApproved() && $return->reviewedBy)
            <div class="reviewer-note">
                <strong>✓ Aprobada</strong>
                Revisada y aprobada por {{ $return->reviewedBy->name }}
                @if($return->reviewed_at) el {{ $return->reviewed_at->format('d/m/Y \a \l\a\s H:i') }} @endif.
            </div>
            @endif

        </div>
        @endforeach

        {{-- Subtotal del grupo de manifiesto --}}
        @if(!$manifest)
        <table class="totals-table" style="margin-top:4px; margin-bottom:12px;">
            <tr>
                <td class="tl-label">Subtotal Manifiesto #{{ $manifestNumber }}</td>
                <td class="tl-value">L {{ number_format($manifestData['subtotal'], 2) }}</td>
            </tr>
        </table>
        @endif

    @empty
    <div style="text-align:center; color:#888; padding:30px; font-size:9pt;">
        No se encontraron devoluciones con los filtros aplicados.
    </div>
    @endforelse

    {{-- ── TABLA DE TOTALES FINAL ───────────────────────────────────── --}}
    @if($byManifest->count() > 0)
    <table class="totals-table" style="margin-top:16px;">
        <tr>
            <td class="tl-label">Total de devoluciones en reporte</td>
            <td class="tl-value">{{ $totals['count'] }}</td>
        </tr>
        <tr>
            <td class="tl-label">Devoluciones aprobadas</td>
            <td class="tl-value" style="color:#166534;">{{ $totals['approved'] }}</td>
        </tr>
        <tr>
            <td class="tl-label">Devoluciones pendientes</td>
            <td class="tl-value" style="color:#92400e;">{{ $totals['pending'] }}</td>
        </tr>
        <tr>
            <td class="tl-label">Devoluciones rechazadas</td>
            <td class="tl-value" style="color:#991b1b;">{{ $totals['rejected'] }}</td>
        </tr>
        <tr>
            <td class="tl-label">Monto total (todas las devoluciones)</td>
            <td class="tl-value">L {{ number_format($totals['total'], 2) }}</td>
        </tr>
        <tr>
            <td class="tl-label">Monto aprobado (impacto real en manifiesto)</td>
            <td class="tl-value" style="color:#166534;">L {{ number_format($totals['approved_amount'], 2) }}</td>
        </tr>
        <tr>
            <td class="tl-label">Monto pendiente (por resolver)</td>
            <td class="tl-value" style="color:#92400e;">L {{ number_format($totals['pending_amount'], 2) }}</td>
        </tr>
        <tr class="grand-row">
            <td>TOTAL GENERAL APROBADO</td>
            <td style="text-align:right;">L {{ number_format($totals['approved_amount'], 2) }}</td>
        </tr>
    </table>
    @endif

    {{-- ── PIE DE PÁGINA ────────────────────────────────────────────── --}}
    <div class="report-footer">
        Distribuidora Hosana &nbsp;·&nbsp; Reporte de Devoluciones
        @if($manifest) &nbsp;·&nbsp; Manifiesto #{{ $manifest->number }} @endif
        &nbsp;·&nbsp; Generado el {{ $generatedAt }}
        &nbsp;·&nbsp; Este documento es de uso interno
    </div>

</div>
</div>
</body>
</html>
