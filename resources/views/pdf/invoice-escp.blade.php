<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Imprimir matriz — Manifiesto {{ $manifest->number }}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: #e5e7eb; font-family: Arial, sans-serif; }

    #toolbar {
        position: sticky; top: 0; z-index: 10;
        background: #1e3a5f; color: #fff;
        padding: 10px 20px;
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,.3);
    }
    #toolbar .info strong { font-size: 15px; }
    #toolbar .info span { font-size: 12px; color: #aac4e8; }
    #toolbar .actions { display: flex; align-items: center; gap: 10px; }
    .btn {
        border: none; border-radius: 6px; padding: 9px 18px;
        font-size: 14px; font-weight: bold; cursor: pointer; text-decoration: none;
        display: inline-flex; align-items: center; gap: 6px;
    }
    .btn-print { background: #f59e0b; color: #000; }
    .btn-print:hover { background: #d97706; }
    .btn-alt { background: #475569; color: #fff; }
    .btn-alt:hover { background: #334155; }
    #status { font-size: 12px; color: #ffd; min-width: 160px; }

    .sheet {
        background: #fff; max-width: 1100px; margin: 16px auto;
        padding: 10mm 8mm; box-shadow: 0 2px 8px rgba(0,0,0,.15);
    }
    pre.preview {
        font-family: 'Courier New', Courier, monospace;
        font-size: 11px; line-height: 1.25; color: #000;
        white-space: pre; font-weight: bold;
        overflow-x: auto;
    }
    .hint {
        max-width: 1100px; margin: 0 auto; padding: 8px 20px;
        font-size: 12px; color: #475569;
    }
</style>
</head>
<body>

<div id="toolbar">
    <div class="info">
        <strong>Manifiesto #{{ $manifest->number }} · Impresión matriz (LX-350)</strong><br>
        <span>{{ $invoices->count() }} {{ $invoices->count() === 1 ? 'factura' : 'facturas' }} · vista = lo que se imprime</span>
    </div>
    <div class="actions">
        <span id="status">QZ Tray: verificando…</span>
        <button class="btn btn-print" id="btnPrint" onclick="imprimirMatriz()">🖨️ Imprimir</button>
        <a class="btn btn-alt" href="{{ route('invoices.print.escp.prn', ['payload' => request('payload')]) }}">⬇ Descargar .prn</a>
    </div>
</div>

<div class="hint">
    La vista de abajo muestra el texto exacto que recibe la impresora (mismas columnas y posiciones).
    En papel sale con la fuente interna de la Epson. Si el botón Imprimir no responde, abrí QZ Tray
    o usá “Descargar .prn”.
</div>

<div class="sheet">
    <pre class="preview">{{ $previewText }}</pre>
</div>

<script src="https://cdn.jsdelivr.net/npm/qz-tray@2.2.4/qz-tray.js"></script>
<script>
    const ESCP_B64    = @json($escpBase64);
    const PRINTER_HINT = @json($printerHint);
    const CONFIRM_URL = @json(route('invoices.print.confirm'));
    const CSRF        = @json(csrf_token());
    const INVOICE_IDS = @json($invoiceIds);

    const statusEl = document.getElementById('status');
    const btn = document.getElementById('btnPrint');

    function setStatus(text, ok) {
        statusEl.textContent = text;
        statusEl.style.color = ok ? '#bbf7d0' : '#fecaca';
    }

    // Configuración de seguridad para QZ Tray sin certificado (community):
    // resolvemos vacío → QZ muestra el diálogo "permitir" una sola vez por PC.
    function setupQz() {
        if (typeof qz === 'undefined') return false;
        qz.security.setCertificatePromise(function (resolve) { resolve(); });
        qz.security.setSignaturePromise(function () {
            return function (resolve) { resolve(); };
        });
        return true;
    }

    async function ensureConnected() {
        if (!setupQz()) throw new Error('No se cargó QZ Tray (revisá tu conexión).');
        if (!qz.websocket.isActive()) {
            await qz.websocket.connect({ retries: 1, delay: 1 });
        }
    }

    async function resolvePrinter() {
        let printer;
        if (PRINTER_HINT) {
            try { printer = await qz.printers.find(PRINTER_HINT); } catch (e) { printer = null; }
        }
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
        } catch (e) { /* silencioso: el admin puede marcar a mano */ }
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
                  '\n\nVerificá que QZ Tray esté abierto en esta PC, o usá "Descargar .prn".');
        } finally {
            btn.disabled = false;
        }
    }

    // Chequeo inicial de disponibilidad de QZ Tray.
    (async function () {
        if (typeof qz === 'undefined') { setStatus('QZ Tray: librería no cargó', false); return; }
        try {
            setupQz();
            await qz.websocket.connect({ retries: 1, delay: 1 });
            setStatus('QZ Tray: conectado ✓', true);
        } catch (e) {
            setStatus('QZ Tray: no detectado (abrilo)', false);
        }
    })();
</script>

</body>
</html>
