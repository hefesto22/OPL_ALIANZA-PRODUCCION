<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Facturas (Formato Hosana) — Manifiesto {{ $manifest->number }}</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }

#toolbar {
    position: fixed; top:0; left:0; right:0; z-index:9999;
    background:#1e3a5f; color:#fff; padding:10px 20px;
    display:flex; align-items:center; justify-content:space-between;
    font-family: Arial, sans-serif; font-size:13px;
    box-shadow:0 2px 8px rgba(0,0,0,.3);
}
#toolbar .info strong { font-size:15px; }
#toolbar .info span { font-size:12px; color:#aac4e8; }
#toolbar .btn-print {
    background:#f59e0b; color:#000; border:none; padding:8px 22px;
    border-radius:6px; font-size:14px; font-weight:bold; cursor:pointer;
}
#toolbar .btn-print:hover { background:#d97706; }

#invoices { margin-top:54px; padding:10px 0; background:#e5e7eb; }

.invoice-page {
    background:#fff; width:215.9mm; min-height:279.4mm;
    margin:0 auto 12px auto; padding:10mm 8mm;
    box-shadow:0 2px 8px rgba(0,0,0,.15);
    box-sizing:border-box;
    /* Formato Hosana: monoespaciada GRANDE (pocas columnas → cabe ~10pt),
       negrita + negro pleno para legibilidad en matriz de punto. */
    font-family: 'Courier New', Courier, monospace;
    font-size: 10pt;
    line-height: 1.3;
    color:#000;
    font-weight: bold;
    -webkit-font-smoothing: none;
}

.center { text-align:center; }
.r { text-align:right; }
.title { font-size:11pt; }

@media print {
    #toolbar { display:none !important; }
    #invoices { margin-top:0; padding:0; background:none; }
}

table.lines { width:100%; border-collapse:collapse; table-layout:fixed; margin-top:2px; }
table.lines th, table.lines td { padding:1px 2px; overflow:hidden; white-space:nowrap; }
table.lines th { border-top:1px solid #000; border-bottom:1px solid #000; text-align:left; }
.hr { border-top:1px solid #000; margin:3px 0; }
.totales td { padding:0 2px; }
</style>

{{-- Estilo de impresión por defecto (Carta vertical). Se DESACTIVA por JS
     cuando se imprime con el botón verde (forma 9.5x5.5 sin QZ), para que
     mande solo el @page de la forma y no se peleen los tamaños. --}}
<style id="printLetter">
@media print {
    @page { size: 215.9mm 279.4mm portrait; margin: 0; }
    .invoice-page { width:215.9mm; min-height:279.4mm; margin:0; box-shadow:none;
                    page-break-after: always; page-break-inside: avoid; }
    .invoice-page:last-child { page-break-after: avoid; }
}
</style>
</head>
<body>

<div id="toolbar">
    <div class="info">
        <strong>Manifiesto #{{ $manifest->number }} · Formato Hosana</strong>
        <span id="status">QZ Tray: verificando…</span>
    </div>
    <div style="display:flex; align-items:center; gap:10px;">
        <button class="btn-print" style="background:#16a34a;color:#fff;" id="btnNavegador" onclick="imprimirNavegador()">🖨️ Imprimir (navegador · sin QZ)</button>
        <button class="btn-print" id="btnMatriz" onclick="imprimirMatriz()">🖨️ Imprimir en LX-350 (sin desperdicio)</button>
        <a class="btn-print" style="background:#475569;color:#fff;text-decoration:none;padding:8px 16px;border-radius:6px;font-weight:bold;" href="{{ route('invoices.print.hosana.prn', ['payload' => request('payload')]) }}">⬇ .prn</a>
    </div>
</div>

<div id="invoices">
@foreach($invoices as $invoice)
@php
    $municipality = strtoupper(trim((string) ($invoice->municipality ?? '')));
    $department   = strtoupper(trim((string) ($invoice->department ?? '')));
    $loc = $municipality !== '' && $department !== ''
        ? "{$municipality} DEPARTAMENTO DE {$department} HONDURAS"
        : trim("{$municipality} {$department}");
    $dir1 = trim(($invoice->address ?? '').' '.$loc);
    $dir2 = trim('Barrio '.($invoice->neighborhood ?? '').' '.$municipality);
@endphp
<div class="invoice-page">

    {{-- ══ Encabezado emisor (centrado) ══ --}}
    <div class="center title">GRUPO JAREMAR DE HONDURAS S.A. DE C.V.</div>
    <div class="center">Bo: La Guadalupe Cl: Las Acacias Apto:13 Edif: Italia M.D.C. F.M. Honduras - Matriz</div>
    <div class="center">Tel: 2238-2484/2561-7410 &nbsp; RTN: 08019017952895 &nbsp; No. Guia Remision: {{ $invoice->manifest->number ?? '' }}</div>
    <div class="center">Correo: finanzas@jaremar.com &nbsp; Sucursal: KM 15 Carret. a Bufalo Villanueva CTS HN</div>
    <div class="center">Tel: 2561-7410/2561-7411 &nbsp; No. G. Rem.: {{ $invoice->manifest->number ?? '' }}</div>
    <div class="center">CAI: {{ $invoice->cai ?? '' }}</div>
    <div class="center">Rango autorizado: {{ $invoice->range_start ?? '' }} Al {{ $invoice->range_end ?? '' }}</div>

    <table style="width:100%; margin-top:4px;">
        <tr>
            <td style="width:34%;">No. Corr. OCE:</td>
            <td style="width:33%;">No. Corr. CRE:</td>
            <td style="width:33%;">No. Ident. Reg. S.A.G.:</td>
        </tr>
    </table>

    {{-- ══ Factura / Cliente ══ --}}
    <div style="margin-top:6px;">Factura: {{ $invoice->invoice_number }} &nbsp; Fecha: {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d/m/Y') }} &nbsp; Limite: {{ $invoice->print_limit_date ? \Carbon\Carbon::parse($invoice->print_limit_date)->format('d/m/Y') : '' }}</div>
    <div>Cliente: {{ $invoice->client_id }}-{{ $invoice->client_name }} &nbsp; RTN: {{ $invoice->client_rtn }} &nbsp; Pago: {{ $invoice->payment_type ?? 'CONTADO' }} &nbsp; Ruta: {{ $invoice->route_number }}</div>
    <div>Direccion:</div>
    <div>{{ $dir1 }}</div>
    <div>{{ $dir2 }}</div>

    {{-- ══ Tabla de líneas ══ --}}
    <table class="lines">
        <thead>
            <tr>
                <th style="width:5%;">Cj</th>
                <th style="width:5%;">Und</th>
                <th style="width:13%;">Código</th>
                <th style="width:37%;">Descripción</th>
                <th style="width:11%; text-align:right;">P.Unit</th>
                <th style="width:10%; text-align:right;">SubT</th>
                <th style="width:8%; text-align:right;">Imp</th>
                <th style="width:11%; text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->lines as $line)
            @php
                $imp = (float) ($line->tax ?? 0) + (float) ($line->tax18 ?? 0);
            @endphp
            <tr>
                <td>{{ number_format((float) $line->quantity_box, 0) }}</td>
                <td>{{ number_format((float) $line->quantity_fractions, 0) }}</td>
                <td>{{ $line->product_id }}</td>
                <td>{{ $line->product_description }}</td>
                <td class="r">{{ number_format((float) $line->price, 2) }}</td>
                <td class="r">{{ number_format((float) $line->subtotal, 2) }}</td>
                <td class="r">{{ number_format($imp, 2) }}</td>
                <td class="r">{{ number_format((float) $line->total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <div class="hr"></div>

    {{-- ══ Totales (derecha) ══ --}}
    <table style="width:100%;">
        <tr>
            <td style="width:55%;">&nbsp;</td>
            <td class="totales" style="width:30%;">SubTotal:</td>
            <td class="totales r" style="width:15%;">L. {{ number_format((float) ($invoice->importe_gravado ?? 0) + (float) ($invoice->importe_excento ?? 0) + (float) ($invoice->importe_exonerado ?? 0), 2) }}</td>
        </tr>
        <tr>
            <td>&nbsp;</td>
            <td class="totales">Impuesto 18%:</td>
            <td class="totales r">L. {{ number_format((float) ($invoice->isv18 ?? 0), 2) }}</td>
        </tr>
        <tr>
            <td>&nbsp;</td>
            <td class="totales">Impuesto 15%:</td>
            <td class="totales r">L. {{ number_format((float) ($invoice->isv15 ?? 0), 2) }}</td>
        </tr>
        <tr>
            <td>&nbsp;</td>
            <td class="totales"><strong>TOTAL:</strong></td>
            <td class="totales r"><strong>L. {{ number_format((float) $invoice->total, 2) }}</strong></td>
        </tr>
    </table>

    <div style="margin-top:6px;">SON: {{ strtoupper(\App\Helpers\NumberHelper::toWords((float) $invoice->total)) }}</div>

    {{-- ══ Firmas ══ --}}
    <table class="firmas" style="width:100%; margin-top:18mm; text-align:center;">
        <tr>
            <td style="width:33%; border-top:1px solid #000;">Nombre Completo</td>
            <td style="width:4%;">&nbsp;</td>
            <td style="width:30%; border-top:1px solid #000;">No. Identificación</td>
            <td style="width:4%;">&nbsp;</td>
            <td style="width:29%; border-top:1px solid #000;">Firma de Recibido</td>
        </tr>
    </table>

</div>
@endforeach
</div>

<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js"></script>
<script>
    const ESCP_B64     = @json($escpBase64);
    const PRINTER_HINT = @json($printerHint);
    const CONFIRM_URL  = @json(route('invoices.print.confirm'));
    const CSRF         = @json(csrf_token());
    const INVOICE_IDS  = @json($invoiceIds);

    const statusEl = document.getElementById('status');
    const btn = document.getElementById('btnMatriz');

    function setStatus(t, ok) { statusEl.textContent = t; statusEl.style.color = ok ? '#bbf7d0' : '#fecaca'; }

    function setupQz() {
        if (typeof qz === 'undefined') return false;
        qz.security.setCertificatePromise(function (resolve) { resolve(); });
        qz.security.setSignaturePromise(function () { return function (resolve) { resolve(); }; });
        return true;
    }

    async function ensureConnected() {
        if (!setupQz()) throw new Error('No se cargó QZ Tray.');
        if (!qz.websocket.isActive()) await qz.websocket.connect({ retries: 1, delay: 1 });
    }

    async function resolvePrinter() {
        let printer;
        if (PRINTER_HINT) { try { printer = await qz.printers.find(PRINTER_HINT); } catch (e) { printer = null; } }
        if (!printer) printer = await qz.printers.getDefault();
        if (Array.isArray(printer)) printer = printer[0];
        return printer;
    }

    async function marcarImpresas() {
        try {
            await fetch(CONFIRM_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: JSON.stringify({ invoice_ids: INVOICE_IDS }),
                credentials: 'same-origin',
            });
        } catch (e) { /* silencioso */ }
    }

    async function imprimirMatriz() {
        btn.disabled = true;
        try {
            setStatus('Conectando con QZ Tray…', true);
            await ensureConnected();
            const printer = await resolvePrinter();
            if (!printer) throw new Error('No se encontró ninguna impresora.');
            setStatus('Enviando a: ' + printer, true);
            const cfg = qz.configs.create(printer);
            await qz.print(cfg, [{ type: 'raw', format: 'base64', data: ESCP_B64 }]);
            await marcarImpresas();
            setStatus('✓ Enviado a ' + printer, true);
        } catch (e) {
            console.error(e);
            setStatus('✗ ' + (e && e.message ? e.message : e), false);
            alert('No se pudo imprimir vía QZ Tray:\n' + (e && e.message ? e.message : e) +
                  '\n\nVerificá que QZ Tray esté abierto, o usá "⬇ .prn".');
        } finally {
            btn.disabled = false;
        }
    }

    // ── Impresión por navegador (window.print) SIN QZ ──────────────
    // Inyecta al vuelo el tamaño de la forma real (9.5"x5.5" = 241.3x139.7mm)
    // con márgenes cero; el alineado fino se hace moviendo el papel en la
    // LX-350. Al terminar, limpia el estilo para no afectar al flujo de QZ.
    function imprimirNavegador() {
        // Desactiva el @page Carta para que mande SOLO la forma 9.5x5.5".
        var letterEl = document.getElementById('printLetter');
        if (letterEl && letterEl.sheet) letterEl.sheet.disabled = true;

        var style = document.createElement('style');
        style.id = 'formPrintStyle';
        style.textContent =
            '@media print {' +
            '  @page { size: 241.3mm 139.7mm; margin: 0; }' +
            '  html, body { margin:0; padding:0; }' +
            '  body.printing-form #invoices { margin:0; padding:0; background:none; }' +
            '  body.printing-form .invoice-page {' +
            '    width:241.3mm; min-height:139.7mm; margin:0; padding:4mm 6mm;' +
            '    box-shadow:none; page-break-after:always; page-break-inside:avoid;' +
            '    font-size:8.5pt; line-height:1.12;' +
            '  }' +
            '  body.printing-form .invoice-page:last-child { page-break-after:avoid; }' +
            '  body.printing-form .title { font-size:9.5pt; }' +
            '  body.printing-form .firmas { margin-top:5mm !important; }' +
            '}';
        document.head.appendChild(style);
        document.body.classList.add('printing-form');

        function cleanup() {
            document.body.classList.remove('printing-form');
            var s = document.getElementById('formPrintStyle');
            if (s) s.remove();
            var l = document.getElementById('printLetter');
            if (l && l.sheet) l.sheet.disabled = false;
            window.removeEventListener('afterprint', onAfter);
        }
        function onAfter() { marcarImpresas(); cleanup(); }

        window.addEventListener('afterprint', onAfter);
        window.print();
    }

    (async function () {
        if (typeof qz === 'undefined') { setStatus('QZ Tray: librería no cargó', false); return; }
        try { setupQz(); await qz.websocket.connect({ retries: 1, delay: 1 }); setStatus('QZ Tray: conectado ✓', true); }
        catch (e) { setStatus('QZ Tray: no detectado (abrilo)', false); }
    })();
</script>

</body>
</html>
