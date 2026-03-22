<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<title>Facturas — Manifiesto #{{ $manifest->number }}</title>
<style>
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
    background: #f59e0b; color: #000; border: none;
    padding: 8px 22px; border-radius: 6px;
    font-size: 14px; font-weight: bold; cursor: pointer;
}
body { font-family: Arial, sans-serif; font-size: 9pt; color: #000; background: #e5e7eb; margin: 0; }
#report-container { margin-top: 60px; padding: 16px; }
.report-page {
    background: #fff; width: 270mm;
    margin: 0 auto 16px auto; padding: 10mm;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15); box-sizing: border-box;
}
.report-header { border-bottom: 2px solid #1e3a5f; padding-bottom: 8px; margin-bottom: 12px; }
.report-header .company { font-size: 13pt; font-weight: bold; color: #1e3a5f; }
.report-header .title   { font-size: 11pt; font-weight: bold; margin: 2px 0; }
.report-header .meta    { font-size: 8pt; color: #555; }

.summary-box { display: flex; gap: 10px; margin-bottom: 14px; }
.summary-card {
    flex: 1; border: 1px solid #ddd; border-radius: 4px;
    padding: 8px 10px; background: #f8fafc; text-align: center;
}
.summary-card .label { font-size: 7.5pt; color: #666; }
.summary-card .value { font-size: 11pt; font-weight: bold; color: #1e3a5f; margin-top: 2px; }

.route-header {
    background: #e2e8f0; padding: 5px 8px;
    font-weight: bold; font-size: 8.5pt;
    border-left: 3px solid #1e3a5f;
    margin-top: 12px; margin-bottom: 2px;
    display: flex; justify-content: space-between;
}

table.data { width: 100%; border-collapse: collapse; font-size: 7pt; }
table.data thead tr { background: #1e3a5f; color: #fff; }
table.data thead th { padding: 4px 5px; text-align: left; white-space: nowrap; }
table.data thead th.r { text-align: right; }
table.data tbody tr:nth-child(even) { background: #f8fafc; }
table.data tbody td { padding: 3px 5px; border-bottom: 1px solid #f0f0f0; }
table.data tbody td.r { text-align: right; }
table.data tbody td.c { text-align: center; }
.subtotal-row { background: #dbeafe !important; font-weight: bold; }
.subtotal-row td { padding: 4px 5px; font-size: 7.5pt; border-top: 1px solid #93c5fd; }
table.data tfoot tr { background: #1e3a5f; color: #fff; font-weight: bold; }
table.data tfoot td { padding: 5px 6px; font-size: 8pt; }
table.data tfoot td.r { text-align: right; }

.badge { display: inline-block; padding: 1px 5px; border-radius: 10px; font-size: 6pt; font-weight: bold; }
.badge-imported       { background: #dbeafe; color: #1e40af; }
.badge-partial_return { background: #fef3c7; color: #92400e; }
.badge-returned       { background: #fee2e2; color: #991b1b; }

.report-footer { margin-top: 14px; border-top: 1px solid #ddd; padding-top: 6px; font-size: 7pt; color: #888; text-align: center; }

@media print {
    @page { size: 270mm auto landscape; margin: 0; }
    #toolbar { display: none !important; }
    body { background: none; }
    #report-container { margin-top: 0; padding: 0; }
    .report-page { width: 270mm; margin: 0; box-shadow: none; page-break-after: always; }
    .report-page:last-child { page-break-after: avoid; }
}
</style>
</head>
<body>

<div id="toolbar">
    <div class="info">
        <strong>Facturas — Manifiesto #{{ $manifest->number }}</strong>
        <span>{{ $totals['count'] }} facturas · {{ $byRoute->count() }} rutas · Generado: {{ $generatedAt }}</span>
    </div>
    <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Guardar PDF</button>
</div>

<div id="report-container">
<div class="report-page">

    <div class="report-header">
        <table><tr>
            <td>
                <div class="company">Distribuidora Hosana</div>
                <div class="title">Reporte de Facturas — Manifiesto #{{ $manifest->number }}</div>
                <div class="meta">
                    Proveedor: {{ $supplier?->name ?? 'Grupo Jaremar de Honduras S.A. de C.V.' }}
                    &nbsp;·&nbsp; Fecha Manifiesto: {{ $manifest->date ? \Carbon\Carbon::parse($manifest->date)->format('d/m/Y') : '—' }}
                    &nbsp;·&nbsp; Estado:
                    {{ match($manifest->status) { 'closed' => 'Cerrado', 'imported' => 'Importado', 'pending' => 'Pendiente', default => $manifest->status } }}
                    &nbsp;·&nbsp; Generado: {{ $generatedAt }}
                </div>
            </td>
        </tr></table>
    </div>

    {{-- RESUMEN --}}
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
            <div class="label">Total Manifiesto</div>
            <div class="value" style="font-size:9pt;">L {{ number_format($totals['total'], 2) }}</div>
        </div>
        <div class="summary-card" style="border-color:#fca5a5;">
            <div class="label">Total Devoluciones</div>
            <div class="value" style="font-size:9pt; color:#991b1b;">L {{ number_format($totals['total_returns'], 2) }}</div>
        </div>
        <div class="summary-card" style="border-color:#86efac;">
            <div class="label">Neto a Depositar</div>
            <div class="value" style="font-size:9pt; color:#166534;">L {{ number_format($totals['net'], 2) }}</div>
        </div>
        <div class="summary-card">
            <div class="label">ISV 15%</div>
            <div class="value" style="font-size:9pt;">L {{ number_format($totals['total_isv15'], 2) }}</div>
        </div>
        <div class="summary-card">
            <div class="label">ISV 18%</div>
            <div class="value" style="font-size:9pt;">L {{ number_format($totals['total_isv18'], 2) }}</div>
        </div>
    </div>

    {{-- FACTURAS AGRUPADAS POR RUTA --}}
    @forelse($byRoute as $route => $routeData)
    <div class="route-header">
        <span>Ruta: {{ $route ?: '(Sin ruta)' }} &nbsp; — &nbsp; {{ $routeData['count'] }} facturas</span>
        <span>Subtotal: L {{ number_format($routeData['subtotal'], 2) }}</span>
    </div>
    <table class="data">
        <thead>
            <tr>
                <th># Factura</th>
                <th>Fecha</th>
                <th>Almacén</th>
                <th>Cód. Cliente</th>
                <th>Cliente</th>
                <th>RTN</th>
                <th>Municipio</th>
                <th>Tipo Pago</th>
                <th>Estado</th>
                <th class="r">ISV 15%</th>
                <th class="r">ISV 18%</th>
                <th class="r">Total (HNL)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($routeData['invoices'] as $invoice)
            <tr>
                <td><strong>{{ $invoice->invoice_number }}</strong></td>
                <td>{{ $invoice->invoice_date ? \Carbon\Carbon::parse($invoice->invoice_date)->format('d/m/Y') : '—' }}</td>
                <td>{{ $invoice->warehouse?->code ?? '—' }}</td>
                <td>{{ $invoice->client_id }}</td>
                <td>{{ $invoice->client_name }}</td>
                <td>{{ $invoice->client_rtn }}</td>
                <td>{{ $invoice->municipality }}</td>
                <td>{{ $invoice->payment_type }}</td>
                <td>
                    <span class="badge badge-{{ $invoice->status }}">
                        {{ match($invoice->status) { 'imported' => 'Importada', 'partial_return' => 'Dev. Parcial', 'returned' => 'Devuelta', default => $invoice->status } }}
                    </span>
                </td>
                <td class="r">{{ number_format($invoice->isv15, 2) }}</td>
                <td class="r">{{ number_format($invoice->isv18, 2) }}</td>
                <td class="r"><strong>L {{ number_format($invoice->total, 2) }}</strong></td>
            </tr>
            @endforeach
            <tr class="subtotal-row">
                <td colspan="9">Subtotal Ruta {{ $route }}</td>
                <td class="r">L {{ number_format($routeData['invoices']->sum('isv15'), 2) }}</td>
                <td class="r">L {{ number_format($routeData['invoices']->sum('isv18'), 2) }}</td>
                <td class="r">L {{ number_format($routeData['subtotal'], 2) }}</td>
            </tr>
        </tbody>
    </table>
    @empty
    <p style="text-align:center; color:#888; padding:20px;">No hay facturas para este manifiesto.</p>
    @endforelse

    {{-- TOTAL GENERAL --}}
    @if($byRoute->count() > 0)
    <table class="data" style="margin-top:8px;">
        <tfoot>
            <tr>
                <td colspan="9"><strong>TOTAL GENERAL — {{ $totals['count'] }} facturas</strong></td>
                <td class="r">L {{ number_format($totals['total_isv15'], 2) }}</td>
                <td class="r">L {{ number_format($totals['total_isv18'], 2) }}</td>
                <td class="r">L {{ number_format($totals['total'], 2) }}</td>
            </tr>
        </tfoot>
    </table>
    @endif

    <div class="report-footer">
        Distribuidora Hosana &nbsp;·&nbsp; {{ $generatedAt }} &nbsp;·&nbsp; Manifiesto #{{ $manifest->number }}
    </div>
</div>
</div>
</body>
</html>