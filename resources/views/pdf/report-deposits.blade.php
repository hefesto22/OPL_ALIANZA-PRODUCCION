<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<title>Reporte de Depósitos — Distribuidora Hosana</title>
<style>
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
body { font-family: Arial, sans-serif; font-size: 9pt; color: #000; background: #e5e7eb; margin: 0; }
#report-container { margin-top: 60px; padding: 16px; }
.report-page {
    background: #fff; width: 220mm;
    margin: 0 auto 16px auto; padding: 12mm;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15); box-sizing: border-box;
}
.report-header { border-bottom: 2px solid #1e40af; padding-bottom: 8px; margin-bottom: 12px; }
.report-header .company { font-size: 13pt; font-weight: bold; color: #1e40af; }
.report-header .title   { font-size: 11pt; font-weight: bold; margin: 2px 0; }
.report-header .meta    { font-size: 8pt; color: #555; }

.summary-box { display: flex; gap: 10px; margin-bottom: 14px; }
.summary-card { flex: 1; border: 1px solid #ddd; border-radius: 4px; padding: 8px 10px; background: #f8fafc; text-align: center; }
.summary-card .label { font-size: 7.5pt; color: #666; }
.summary-card .value { font-size: 11pt; font-weight: bold; color: #1e40af; margin-top: 2px; }

.bank-header {
    background: #dbeafe; padding: 5px 8px; font-weight: bold; font-size: 8.5pt;
    border-left: 3px solid #1e40af; margin-top: 12px; margin-bottom: 2px;
    display: flex; justify-content: space-between;
}

table.data { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
table.data thead tr { background: #1e40af; color: #fff; }
table.data thead th { padding: 4px 6px; text-align: left; white-space: nowrap; }
table.data thead th.r { text-align: right; }
table.data tbody tr:nth-child(even) { background: #eff6ff; }
table.data tbody td { padding: 4px 6px; border-bottom: 1px solid #e0e7ff; }
table.data tbody td.r { text-align: right; }
.subtotal-row { background: #dbeafe !important; font-weight: bold; }
.subtotal-row td { padding: 4px 6px; font-size: 7.5pt; border-top: 1px solid #93c5fd; }
table.data tfoot tr { background: #1e40af; color: #fff; font-weight: bold; }
table.data tfoot td { padding: 5px 6px; font-size: 8pt; }
table.data tfoot td.r { text-align: right; }

.badge-closed   { display:inline-block; padding:1px 5px; border-radius:10px; font-size:6pt; font-weight:bold; background:#dcfce7; color:#166534; }
.badge-imported { display:inline-block; padding:1px 5px; border-radius:10px; font-size:6pt; font-weight:bold; background:#dbeafe; color:#1e40af; }

.report-footer { margin-top: 14px; border-top: 1px solid #ddd; padding-top: 6px; font-size: 7pt; color: #888; text-align: center; }

@media print {
    @page { size: 220mm auto portrait; margin: 0; }
    #toolbar { display: none !important; }
    body { background: none; }
    #report-container { margin-top: 0; padding: 0; }
    .report-page { width: 220mm; margin: 0; box-shadow: none; page-break-after: always; }
    .report-page:last-child { page-break-after: avoid; }
}
</style>
</head>
<body>

<div id="toolbar">
    <div class="info">
        <strong>Reporte de Depósitos</strong>
        <span>
            @if(!empty($filters['date_from']) || !empty($filters['date_to']))
                Período: {{ !empty($filters['date_from']) ? \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') : '—' }}
                al {{ !empty($filters['date_to']) ? \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') : '—' }}
                &nbsp;·&nbsp;
            @endif
            {{ $totals['count'] }} depósitos · Total: L {{ number_format($totals['total'], 2) }}
        </span>
    </div>
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
</div>

<div id="report-container">
<div class="report-page">

    <div class="report-header">
        <table><tr><td>
            <div class="company">Distribuidora Hosana</div>
            <div class="title">Reporte de Depósitos</div>
            <div class="meta">
                Generado: {{ $generatedAt }}
                @if(!empty($filters['date_from']) || !empty($filters['date_to']))
                    &nbsp;·&nbsp; Período:
                    {{ !empty($filters['date_from']) ? \Carbon\Carbon::parse($filters['date_from'])->format('d/m/Y') : 'inicio' }}
                    al
                    {{ !empty($filters['date_to']) ? \Carbon\Carbon::parse($filters['date_to'])->format('d/m/Y') : 'hoy' }}
                @endif
            </div>
        </td></tr></table>
    </div>

    {{-- RESUMEN --}}
    <div class="summary-box">
        <div class="summary-card">
            <div class="label">Total Depósitos</div>
            <div class="value">{{ $totals['count'] }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Bancos</div>
            <div class="value">{{ $byBank->count() }}</div>
        </div>
        <div class="summary-card" style="border-color:#86efac;">
            <div class="label">Total Depositado</div>
            <div class="value" style="color:#166534; font-size:9pt;">L {{ number_format($totals['total'], 2) }}</div>
        </div>
    </div>

    {{-- DEPÓSITOS AGRUPADOS POR BANCO --}}
    @forelse($byBank as $bank => $bankData)
    <div class="bank-header">
        <span>🏦 {{ $bank }} &nbsp;—&nbsp; {{ $bankData['count'] }} depósitos</span>
        <span>Subtotal: L {{ number_format($bankData['subtotal'], 2) }}</span>
    </div>
    <table class="data">
        <thead>
            <tr>
                <th># Dep.</th>
                <th># Manifiesto</th>
                <th>Estado Manif.</th>
                <th>Fecha Depósito</th>
                <th>Referencia</th>
                <th>Notas</th>
                <th>Registrado por</th>
                <th class="r">Monto (HNL)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($bankData['deposits'] as $deposit)
            <tr>
                <td>{{ $deposit->id }}</td>
                <td><strong>{{ $deposit->manifest?->number ?? '—' }}</strong></td>
                <td>
                    @if($deposit->manifest)
                    <span class="badge-{{ $deposit->manifest->status }}">
                        {{ match($deposit->manifest->status) { 'closed' => 'Cerrado', 'imported' => 'Importado', default => $deposit->manifest->status } }}
                    </span>
                    @else —
                    @endif
                </td>
                <td>{{ $deposit->deposit_date ? \Carbon\Carbon::parse($deposit->deposit_date)->format('d/m/Y') : '—' }}</td>
                <td>{{ $deposit->reference ?? '—' }}</td>
                <td>{{ $deposit->notes ?? '—' }}</td>
                <td>{{ $deposit->createdBy?->name ?? '—' }}</td>
                <td class="r"><strong>L {{ number_format($deposit->amount, 2) }}</strong></td>
            </tr>
            @endforeach
            <tr class="subtotal-row">
                <td colspan="7">Subtotal {{ $bank }}</td>
                <td class="r">L {{ number_format($bankData['subtotal'], 2) }}</td>
            </tr>
        </tbody>
    </table>
    @empty
    <p style="text-align:center; color:#888; padding:20px;">No se encontraron depósitos con los filtros aplicados.</p>
    @endforelse

    @if($byBank->count() > 0)
    <table class="data" style="margin-top:8px;">
        <tfoot>
            <tr>
                <td colspan="7"><strong>TOTAL GENERAL — {{ $totals['count'] }} depósitos</strong></td>
                <td class="r">L {{ number_format($totals['total'], 2) }}</td>
            </tr>
        </tfoot>
    </table>
    @endif

    <div class="report-footer">
        Distribuidora Hosana &nbsp;·&nbsp; {{ $generatedAt }} &nbsp;·&nbsp; Reporte de Depósitos
    </div>
</div>
</div>
</body>
</html>