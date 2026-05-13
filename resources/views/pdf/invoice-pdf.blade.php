<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Facturas — Manifiesto {{ $manifest->number }}</title>
<style>

/* ══ BARRA DE HERRAMIENTAS (solo en pantalla) ════════════════ */
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
#toolbar .info {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
#toolbar .info strong { font-size: 15px; }
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
    display: flex;
    align-items: center;
    gap: 6px;
}
#toolbar .btn-print:hover { background: #d97706; }

/* ══ ÁREA DE FACTURAS ════════════════════════════════════════ */
#invoices-container {
    margin-top: 54px; /* altura del toolbar */
    padding: 10px 0;
    background: #e5e7eb;
}

.invoice-page {
    background: #fff;
    width: 215.9mm;
    min-height: 279.4mm;
    margin: 0 auto 12px auto;
    padding: 8mm 7mm 6mm 7mm;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    box-sizing: border-box;
    position: relative;
}

/* ══ ESTILOS DE IMPRESIÓN ════════════════════════════════════ */
@media print {
    @page {
        size: 215.9mm 279.4mm portrait;
        margin: 0;
    }

    #toolbar {
        display: none !important;
    }

    #invoices-container {
        margin-top: 0;
        padding: 0;
        background: none;
    }

    .invoice-page {
        width: 215.9mm;
        min-height: 279.4mm;
        margin: 0;
        padding: 8mm 7mm 6mm 7mm;
        box-shadow: none;
        page-break-after: always;
        page-break-inside: avoid;
    }

    .invoice-page:last-child {
        page-break-after: avoid;
    }
}

/* ══ ESTILOS DE FACTURA ══════════════════════════════════════ */
* { margin:0; padding:0; box-sizing:border-box; }

.invoice-page {
    font-family: 'Courier New', Courier, monospace;
    font-size: 6.5pt;
    color: #000;
    line-height: 1.2;
}

.bold { font-weight: bold; }
.r    { text-align: right; }
.c    { text-align: center; }

table { border-collapse: collapse; width: 100%; }
td, th { padding: 0 1px; vertical-align: top; }

table.lines {
    border-collapse: collapse;
    width: 100%;
    table-layout: fixed;
    margin-top: 3px;
}
table.lines th {
    font-size: 5.2pt;
    font-weight: bold;
    text-align: center;
    padding: 1px 0px;
    border-top: 1px solid #000;
    border-bottom: 1px solid #000;
    overflow: hidden;
    white-space: nowrap;
}
table.lines td {
    font-size: 5.5pt;
    padding: 1px 0px;
    overflow: hidden;
    white-space: nowrap;
}
.notas {
    margin-top: 3px;
    border-top: 1px solid #000;
    padding-top: 2px;
    font-size: 5.3pt;
    line-height: 1.25;
}
.notas p { margin-bottom: 1px; }
</style>
</head>
<body>

{{-- ══ BARRA SUPERIOR ═════════════════════════════════════════ --}}
<div id="toolbar">
    <div class="info">
        <strong>Manifiesto #{{ $manifest->number }}</strong>
        <span>{{ $invoices->count() }} {{ $invoices->count() === 1 ? 'factura' : 'facturas' }} · Vista previa de impresión</span>
    </div>
    <button class="btn-print" onclick="window.print()">
        🖨️ Imprimir
    </button>
</div>

{{-- ══ FACTURAS ════════════════════════════════════════════════ --}}
<div id="invoices-container">

@foreach($invoices as $invoice)
<div class="invoice-page">

    {{-- ══ ENCABEZADO ═══════════════════════════════════════════ --}}
    <table style="border-bottom:1px solid #000; margin-bottom:2px;">
        <tr>
            <td style="width:54%; font-size:6.5pt;">
                <b>RTN: {{ $supplier->rtn ?? '08019017952895' }}</b>
            </td>
            <td style="width:46%; text-align:right; font-size:6.5pt;">
                <span style="font-size:9pt; font-weight:bold; letter-spacing:2px;">FACTURA</span>
                &nbsp;
                <b style="font-size:7pt;">NT {{ $invoice->invoice_number }}</b>
            </td>
        </tr>
        <tr>
            <td style="font-size:6.5pt;">
                BO:{{ $supplier->neighborhood ?? 'LA GUADALUPE' }}
                CL:{{ $supplier->address ?? 'LAS ACACIAS APTO:13 EDIF: ITALIA M.D.C. F.M. HONDURAS' }}
            </td>
            <td rowspan="5" style="text-align:right; vertical-align:top; font-size:6.5pt;">
                @if(!empty($invoice->barcode_base64))
                    <img src="data:image/png;base64,{{ $invoice->barcode_base64 }}"
                         style="height:22px; display:block; margin-left:auto;"><br>
                @endif
                <b>Fecha:</b> {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('Y/m/d') }}
                &nbsp;<span style="font-size:5.5pt;">COPIA : OBLIGADO TRIBUTARIO EMISOR</span><br>
                <b>C.A.I</b> {{ $invoice->cai ?? '' }}<br>
                <b>CAEB</b>
            </td>
        </tr>
        <tr>
            <td style="font-size:6.5pt;">TEL: {{ $supplier->phone ?? '2238-2484  2561-7410' }} &nbsp; MATRIZ</td>
        </tr>
        <tr>
            <td style="font-size:6.5pt; font-weight:bold;">
                {{ $invoice->matriz_address ?? 'KM 15 CARRETERA A BUFALO   VILLANUEVA CORTES HONDURAS' }}
            </td>
        </tr>
        <tr>
            <td style="font-size:6.5pt;">TEL: {{ $supplier->phone2 ?? '2561-7410  2561-7411' }} &nbsp; SUCURSAL</td>
        </tr>
        <tr>
            <td style="font-size:6.5pt;">{{ $supplier->email ?? 'finanzas@jaremar.com' }}</td>
        </tr>
    </table>

    {{-- ══ GUÍA ═══════════════════════════════════════════════════ --}}
    <table style="margin-top:2px; font-size:6.5pt;">
        <tr>
            <td style="width:50%;">
                <b>No. Guia de Remision</b> {{ $invoice->manifest->number ?? '' }}
                &nbsp;&nbsp; <b>Ped SF</b> {{ $invoice->order_number ?? '' }}
            </td>
            <td style="text-align:right;">
                <b>Fecha Limite Emision</b>
                {{ $invoice->print_limit_date ? \Carbon\Carbon::parse($invoice->print_limit_date)->format('Y/m/d') : '' }}
            </td>
        </tr>
    </table>

    {{-- ══ CLIENTE ════════════════════════════════════════════════ --}}
    <table style="margin-top:2px; font-size:6.5pt;">
        <tr>
            <td style="width:48%; vertical-align:top;">
                <b>Facturado</b> {{ $invoice->client_name }}<br>
                <b>RTN</b> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; {{ $invoice->client_rtn }}<br>
                <b>Direccion</b> {{ $invoice->neighborhood ?? '' }}<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
                {{ trim(($invoice->municipality ?? '') . ' ' . ($invoice->department ?? '')) }}<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; {{ $invoice->address ?? '' }}<br>
                <b>Moneda</b> &nbsp; LEMPIRAS &nbsp; <b>Vendedor</b> &nbsp;
                {{ str_pad($invoice->seller_id ?? '', 6, '0', STR_PAD_LEFT) }}<br>
                <b>Pedido</b> &nbsp;&nbsp;&nbsp;&nbsp; {{ $invoice->order_number ?? '' }}
                &nbsp; <b>Tipo/Clase</b> &nbsp; {{ $invoice->invoice_type ?? 'FAC' }} 004<br>
                <b>Condiciones</b>
                {{ str_pad($invoice->credit_days ?? '00', 2, '0', STR_PAD_LEFT) }}
                &nbsp; CONTADO &nbsp; <b>Motivo Emision</b>
            </td>
            <td style="width:52%; vertical-align:top;">
                <b>Cliente No.</b> {{ $invoice->client_id }}
                &nbsp; <b>Ruta</b> {{ $invoice->route_number }}<br>
                <b>Entregar:</b> {{ $invoice->deliver_to ?? $invoice->client_name }}<br>
                <b>No Correlativo de orden compra exenta:</b><br>
                <b>No Correlativo de constancia de registro exonerado:</b><br>
                <b>No Identificativo del registro de la S.A.G.:</b>
            </td>
        </tr>
    </table>

    {{-- ══ TABLA PRODUCTOS ════════════════════════════════════════ --}}
    <table class="lines">
        <thead>
            <tr>
                <th style="width:24mm; text-align:left;">ARTICULO No</th>
                <th style="width:52mm; text-align:left;">DESCRIPCION</th>
                <th style="width:6mm;">UM</th>
                <th style="width:6mm;">CJ-SC</th>
                <th style="width:6mm;">UN</th>
                <th style="width:9mm;">CANT</th>
                <th style="width:17mm;">PRECIO UNIT</th>
                <th style="width:14mm;">VALOR</th>
                <th style="width:15mm; font-size:4.8pt; white-space:normal; line-height:1.1;">DESCUENTOS<br>Y REBAJAS</th>
                <th style="width:10mm;">18% ISV</th>
                <th style="width:12mm;">15% ISV</th>
                <th style="width:18mm;">VALOR TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->lines as $line)
            <tr>
                <td>{{ $line->product_id }} {{ $line->jaremar_line_id }}</td>
                <td>{{ $line->product_description }}</td>
                <td class="c">{{ $line->unit_sale }}</td>
                <td class="c">{{ strtoupper($line->unit_sale) === 'CJ' ? number_format($line->quantity_box, 0) : '' }}</td>
                <td class="c">{{ strtoupper($line->unit_sale) !== 'CJ' ? number_format($line->quantity_fractions, 0) : '' }}</td>
                <td class="r">{{ number_format($line->quantity_decimal, 3) }}</td>
                <td class="r">{{ number_format($line->price, 3) }}</td>
                @php
                    $isBonus = strtoupper($line->product_type ?? '') === 'B';
                    $displayValor = $isBonus
                        ? round($line->price * $line->quantity_decimal, 2)
                        : $line->subtotal;
                    $displayDiscount = $isBonus
                        ? -(floor($line->price * $line->quantity_decimal * 100) / 100)
                        : ($line->discount ?? 0);
                @endphp
                <td class="r">{{ number_format($displayValor, 2) }}</td>
                <td class="r">{{ number_format($displayDiscount, 2) }}</td>
                <td class="r">.00</td>
                <td class="r">{{ number_format($line->tax ?? 0, 2) }}</td>
                <td class="r">{{ number_format($line->total, 2) }}</td>
            </tr>
            @endforeach
            <tr>
                <td colspan="7" class="r"
                    style="border-top:1px solid #000; font-weight:bold; font-size:5.8pt;">
                    TOTAL A PAGAR L
                </td>
                <td class="r" style="border-top:1px solid #000; font-weight:bold; font-size:5.8pt;">
                    {{ number_format(($invoice->importe_excento ?? 0) + ($invoice->importe_exonerado ?? 0) + ($invoice->importe_gravado ?? 0), 2) }}
                </td>
                <td style="border-top:1px solid #000;"></td>
                <td style="border-top:1px solid #000;"></td>
                <td style="border-top:1px solid #000;"></td>
                <td class="r" style="border-top:1px solid #000; font-weight:bold; font-size:5.8pt;">
                    {{ number_format($invoice->total, 2) }}
                </td>
            </tr>
        </tbody>
    </table>

    {{-- ══ PIE: FIRMAS + IMPORTES ═════════════════════════════════ --}}
    <table style="margin-top:10mm; font-size:6.5pt;">
        <tr>
            <td style="width:42%; vertical-align:bottom;">
                <table style="font-size:6pt; width:100%;">
                    <tr>
                        <td style="width:36%; border-top:1px solid #000;">&nbsp;</td>
                        <td style="width:4%;">&nbsp;</td>
                        <td style="width:42%; border-top:1px solid #000;">&nbsp;</td>
                        <td style="width:18%;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td>NOMBRE COMPLETO</td>
                        <td>&nbsp;</td>
                        <td>NO. DE IDENTIFICACION</td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr><td colspan="4" style="height:8px;">&nbsp;</td></tr>
                    <tr>
                        <td colspan="3" style="border-top:1px solid #000;">&nbsp;</td>
                        <td>&nbsp;</td>
                    </tr>
                    <tr>
                        <td colspan="3">FIRMA DE RECIBIDO</td>
                        <td>&nbsp;</td>
                    </tr>
                </table>
            </td>
            <td style="width:58%; vertical-align:top;">
                <table style="width:100%; font-size:6.5pt;">
                    <tr>
                        <td style="width:30%;">Importe Exento &nbsp; L</td>
                        <td style="width:14%; text-align:right;">{{ number_format($invoice->importe_excento ?? 0, 2) }}</td>
                        <td style="width:9%; text-align:right;">{{ number_format($invoice->importe_exento_desc ?? 0, 2) }}</td>
                        <td style="width:7%; text-align:right;">.00</td>
                        <td style="width:9%; text-align:right;">{{ number_format($invoice->importe_exento_isv15 ?? 0, 2) }}</td>
                        <td style="width:15%; text-align:right; font-weight:bold;">{{ number_format($invoice->importe_exento_total ?? 0, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Importe Rxonerado L</td>
                        <td style="text-align:right;">{{ number_format($invoice->importe_exonerado ?? 0, 2) }}</td>
                        <td style="text-align:right;">{{ number_format($invoice->importe_exonerado_desc ?? 0, 2) }}</td>
                        <td style="text-align:right;">.00</td>
                        <td style="text-align:right;">{{ number_format($invoice->importe_exonerado_isv15 ?? 0, 2) }}</td>
                        <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->importe_exonerado_total ?? 0, 2) }}</td>
                    </tr>
                    <tr>
                        <td>Importe Gravado &nbsp; L</td>
                        <td style="text-align:right;">{{ number_format($invoice->importe_gravado ?? 0, 2) }}</td>
                        <td style="text-align:right;">{{ number_format($invoice->importe_gravado_desc ?? 0, 2) }}</td>
                        <td style="text-align:right;">.00</td>
                        <td style="text-align:right;">{{ number_format($invoice->importe_gravado_isv15 ?? 0, 2) }}</td>
                        <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->importe_gravado_total ?? 0, 2) }}</td>
                    </tr>
                    <tr style="border-top:1px solid #000; font-weight:bold;">
                        <td>TOTAL A PAGAR &nbsp; L</td>
                        <td style="text-align:right;">{{ number_format(($invoice->importe_excento ?? 0) + ($invoice->importe_exonerado ?? 0) + ($invoice->importe_gravado ?? 0), 2) }}</td>
                        <td style="text-align:right;">{{ number_format(($invoice->importe_exento_desc ?? 0) + ($invoice->importe_exonerado_desc ?? 0) + ($invoice->importe_gravado_desc ?? 0), 2) }}</td>
                        <td style="text-align:right;">.00</td>
                        <td style="text-align:right;">{{ number_format($invoice->isv15 ?? 0, 2) }}</td>
                        <td style="text-align:right; font-weight:bold;">{{ number_format($invoice->total, 2) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- SON LEMPIRAS --}}
    <table style="margin-top:2px; font-size:6.5pt;">
        <tr>
            <td style="width:15%; font-weight:bold;">SON LEMPIRAS:</td>
            <td>{{ strtoupper(\App\Helpers\NumberHelper::toWords($invoice->total)) }}</td>
        </tr>
    </table>

    {{-- RANGO --}}
    <table style="margin-top:1px; font-size:6pt;">
        <tr>
            <td style="width:48%;">
                <b>Rango Autorizado:</b> {{ $invoice->range_start }} Al {{ $invoice->range_end }}
            </td>
            <td style="text-align:right;">
                Original:Cliente, &nbsp; Copia :Obligado tributario emisor, &nbsp; Copia :Cliente
            </td>
        </tr>
    </table>
    <div style="font-size:6pt; margin-top:1px;">
        JAMERARI &nbsp; MERTRO1 &nbsp;&nbsp;
        {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('H:i:s') }}
    </div>

    {{-- NOTAS --}}
    <div class="notas">
        <p>1.- LAS FACTURAS Y NOTAS DE DEBITO PAGADAS CON CHEQUE SE CONSIDERAN CANCELADAS EN EL MOMENTO QUE EL BANCO ACEPTA EL CHEQUE. SI EL CHEQUE ES RECHAZADO POR EL BANCO, LA FACTURA ENTRARA INMEDIATAMENTE EN MORA Y EL CLIENTE SUFRAGARA TODOS LOS GASTOS CORRESPONDIENTE</p>
        <p>2.- LAS FACTURAS Y NOTAS DE DEBITO QUE NO SEAN CANCELADAS EN EL PLAZO PACTADO TENDRAN UN RECARGO SOBRESALDO EN MORA EQUIVALENTE A LA TASA DE INTERES PREVALECIENTE EN ELMERCADO BANCARIO.</p>
        <p>3.- TODA FACTURA AL CREDITO O NOTA DE DEBITO NO SE CONSIDERA  CANCELADA SI  NO ES CON RECIBO EN CAJA.</p>
        <p>4.- TODA NOTA DE CREDITO DEBERA SERA APLICADA DENTRO DE UN PLAZO MAXIMO DE  TRES MESES CONTADOS A PARTIR DE LA FECHA DE SU EMSION</p>
    </div>

</div>{{-- fin .invoice-page --}}
@endforeach

</div>{{-- fin #invoices-container --}}

{{--
    ══════════════════════════════════════════════════════════════════════
    Callback de impresión real

    Escucha window.afterprint y notifica al backend para que marque las
    facturas como impresas (is_printed=true, printed_at=now). Sin esto,
    el flag is_printed refleja "se sirvió la vista" en vez de "se imprimió
    físicamente" — generando métrica falsa y bloqueando reimpresiones.

    Si el callback falla (offline, navegador sin soporte, impresión a PDF),
    las facturas quedan como no impresas. El admin puede marcarlas a mano
    desde Filament si fue necesario. Es trade-off intencional: preferir
    falsos negativos antes que falsos positivos en métrica fiscal.
    ══════════════════════════════════════════════════════════════════════
--}}
<script>
(function () {
    var csrfToken = '{{ csrf_token() }}';
    var invoiceIds = {!! json_encode($invoices->pluck('id')->all()) !!};
    var confirmUrl = '{{ route('invoices.print.confirm') }}';
    var confirmed = false;

    function confirmPrint() {
        if (confirmed) return;
        confirmed = true;
        fetch(confirmUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ invoice_ids: invoiceIds }),
            credentials: 'same-origin',
        }).catch(function (err) {
            // Silencioso por diseño: si la confirmación falla, el admin
            // puede marcar manualmente desde Filament. No interrumpimos
            // al operador con un error en mitad del flujo de impresión.
            console.warn('No se pudo confirmar la impresión:', err);
        });
    }

    window.addEventListener('afterprint', confirmPrint);
})();
</script>

</body>
</html>