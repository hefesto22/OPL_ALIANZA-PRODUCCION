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

    {{-- ══ ENCABEZADO ═══════════════════════════════════════════
         Formato fijo del emisor Jaremar — texto literal del papel que
         ellos imprimen. Mantener idéntico para que el PDF coincida 1:1
         con la factura original. Ver memory project_invoice_pdf_jaremar_format.
    ──────────────────────────────────────────────────────────── --}}
    <table style="margin-bottom:2px;">
        <tr>
            <td style="width:54%; font-size:6.5pt;">
                <b>RTN: 08019017952895</b>
            </td>
            <td style="width:46%; text-align:right; font-size:6.5pt;">
                <span style="font-size:9pt; font-weight:bold; letter-spacing:2px;">FACTURA</span>
                &nbsp;
                <b style="font-size:7pt;">NT {{ $invoice->invoice_number }}</b>
            </td>
        </tr>
        <tr>
            <td style="font-size:6.5pt;">
                BO:LA GUADALUPE CL:LAS ACACIAS APTO:13 EDIF: ITALIA M.D.C. F.M. HONDURAS
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
            <td style="font-size:6.5pt;">TEL: 2238-2484  2561-7410 &nbsp;&nbsp; MATRIZ</td>
        </tr>
        <tr>
            <td style="font-size:6.5pt;">KM 15 CARRETERA A BUFALO &nbsp;&nbsp; VILLANUEVA CORTES HONDURAS</td>
        </tr>
        <tr>
            <td style="font-size:6.5pt;">TEL: TEL: 2561-7410 2561-7411 &nbsp;&nbsp; SUCURSAL</td>
        </tr>
        <tr>
            <td style="font-size:6.5pt;">finanzas@jaremar.com</td>
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
                @php
                    // Formato Jaremar de la línea 2 de Direccion:
                    //   "{MUNICIPALITY} DEPARTAMENTO DE {DEPARTMENT} HONDURAS"
                    // Si municipality o department vienen vacíos, se omiten
                    // para evitar imprimir "DEPARTAMENTO DE  HONDURAS" suelto.
                    $municipality = strtoupper(trim($invoice->municipality ?? ''));
                    $department = strtoupper(trim($invoice->department ?? ''));
                    $locationLine = $municipality !== '' && $department !== ''
                        ? "{$municipality} DEPARTAMENTO DE {$department} HONDURAS"
                        : trim("{$municipality} {$department}");
                @endphp
                <b>Direccion</b> {{ $invoice->neighborhood ?? '' }}<br>
                &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; {{ $locationLine }}<br>
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

    {{-- ══ TABLA PRODUCTOS ════════════════════════════════════════
         Formato AS400/COBOL idéntico al que imprime Jaremar.

         ARTICULO No: imprimimos solo el `product_id` (corto, ej. 52480077).
         Jaremar imprime además un código EAN-13 (ej. 7421001615056) que
         NO viene en el JSON del API — está en su sistema interno. Hasta
         que Jaremar añada `CodigoEAN` (u homólogo) al JSON, imprimimos
         solo el ProductoId para evitar mostrar un código equivocado.

         Líneas tipo "B" (bonus): el JSON manda Subtotal/Descuento/Impuesto/
         Total = 0 y Jaremar calcula localmente al imprimir según tax_percent:
           VALOR     = floor(precio × cantidad_decimal × 100) / 100
           DESCUENTO = -VALOR (signo AS400 trailing)
           Si tax_percent == 0  → ISV 15% = .00, TOTAL = .00
           Si tax_percent  > 0  → ISV 15% = round(exact - VALOR, 2)
                                  TOTAL    = ISV 15%
         (El producto bonus sin ISV no genera remanente; el bonus gravado sí.)

         Líneas tipo "A": usamos los valores del API tal cual ($line->subtotal,
         $line->tax, $line->total) — Jaremar ya los manda redondeados.

         Ver NumberHelper::as400() y memory project_invoice_pdf_jaremar_format.
    ──────────────────────────────────────────────────────────── --}}
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
                <th style="width:15mm; font-size:4.8pt; white-space:normal; line-height:1.1;">DESCUENTOS<br>Y REBAJAS<br>OTORGADOS</th>
                <th style="width:10mm; font-size:5pt; line-height:1.1;">18%<br>ISV</th>
                <th style="width:12mm; font-size:5pt; line-height:1.1;">15%<br>ISV</th>
                <th style="width:18mm;">VALOR TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->lines as $line)
            @php
                $isBonus = strtoupper($line->product_type ?? '') === 'B';

                if ($isBonus) {
                    // Replica el cálculo de Jaremar al imprimir bonus.
                    // Si el producto no tiene ISV (tax_percent=0), todo el
                    // descuento es exento → ISV15 y TOTAL salen en cero.
                    // Si sí tiene ISV (tax_percent>0), Jaremar suma el
                    // remanente del truncado al ISV15.
                    $exact = (float) $line->price * (float) $line->quantity_decimal;
                    $valor = floor($exact * 100) / 100;            // 31.95
                    $descuento = -$valor;                           // -31.95 → "31.95-"
                    $hasTax = ((float) ($line->tax_percent ?? 0)) > 0;
                    $isv15 = $hasTax ? round($exact - $valor, 2) : 0.0;
                    $isv18 = 0.0;
                    $total = $isv15;
                } else {
                    // Línea normal: confiar en los valores del API.
                    $valor = (float) $line->subtotal;
                    $descuento = -abs((float) ($line->discount ?? 0));
                    $isv15 = (float) ($line->tax ?? 0);
                    $isv18 = (float) ($line->tax18 ?? 0);
                    $total = (float) $line->total;
                }
            @endphp
            <tr>
                <td>{{ $line->product_id }}</td>
                <td>{{ $line->product_description }}</td>
                <td class="c">{{ $line->unit_sale }}</td>
                <td class="c">{{ strtoupper($line->unit_sale) === 'CJ' ? number_format($line->quantity_box, 0) : '' }}</td>
                <td class="c">{{ strtoupper($line->unit_sale) !== 'CJ' ? number_format($line->quantity_fractions, 0) : '' }}</td>
                <td class="r">{{ \App\Helpers\NumberHelper::as400($line->quantity_decimal, 3) }}</td>
                <td class="r">{{ \App\Helpers\NumberHelper::as400($line->price, 3) }}</td>
                <td class="r">{{ \App\Helpers\NumberHelper::as400($valor, 2) }}</td>
                <td class="r">{{ \App\Helpers\NumberHelper::as400($descuento, 2) }}</td>
                <td class="r">{{ \App\Helpers\NumberHelper::as400($isv18, 2) }}</td>
                <td class="r">{{ \App\Helpers\NumberHelper::as400($isv15, 2) }}</td>
                <td class="r">{{ \App\Helpers\NumberHelper::as400($total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- ══ PIE: FIRMAS + IMPORTES ═════════════════════════════════
         Replica exacta del pie fiscal Jaremar (factura completa).
         Todos los números usan NumberHelper::as400() para mantener el
         formato COBOL/AS400: .00 sin cero entero, signo "-" trailing en
         descuentos. Ver memory project_invoice_pdf_jaremar_format.
    ──────────────────────────────────────────────────────────── --}}
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
                @php
                    // ── Helpers AS400 ────────────────────────────────────
                    $asNum = fn ($v) => \App\Helpers\NumberHelper::as400((float) $v, 2);
                    $asDiscount = fn ($v) => \App\Helpers\NumberHelper::as400(-abs((float) $v), 2);

                    // ── Calcular descuento de líneas bonus ───────────────
                    // Jaremar reparte el descuento bonus así:
                    //   - bonus con tax_percent = 0  → suma al "exento"
                    //   - bonus con tax_percent > 0  → suma al "gravado"
                    // Esta lógica replica los valores impresos en factura física
                    // y NO los campos *_Desc del JSON (que vienen redondeados
                    // distinto y no siempre se imprimen).
                    $bonusDescExento = 0.0;
                    $bonusDescGravado = 0.0;
                    foreach ($invoice->lines as $bLine) {
                        if (strtoupper($bLine->product_type ?? '') !== 'B') {
                            continue;
                        }
                        $bExact = (float) $bLine->price * (float) $bLine->quantity_decimal;
                        $bValor = floor($bExact * 100) / 100;
                        if (((float) ($bLine->tax_percent ?? 0)) > 0) {
                            $bonusDescGravado += $bValor;
                        } else {
                            $bonusDescExento += $bValor;
                        }
                    }
                    $descuentoTotal = $bonusDescExento + $bonusDescGravado;

                    // ── Algoritmo de Jaremar para filas de Importes ─────
                    // Regla 1 "fila cero": si el VALOR BASE de la fila es 0 →
                    //   TODA la fila imprime ".00" (ignora campos *_Desc del JSON).
                    // Regla 2: la columna DESCUENTO de Exento y Exonerado SIEMPRE
                    //   imprime ".00" (Jaremar no muestra el descuento bonus aquí,
                    //   solo en TOTAL A PAGAR).
                    // Regla 3: el TOTAL de Exento se calcula restando el descuento
                    //   bonus exento (el JSON *_Total puede no reflejarlo).
                    $renderImportRow = function ($label, $base, $isv18, $isv15, $bonusDesc) use ($asNum) {
                        $base = (float) $base;
                        $isv18 = (float) $isv18;
                        $isv15 = (float) $isv15;
                        $bonusDesc = (float) $bonusDesc;

                        if ($base == 0.0) {
                            // Regla "fila cero"
                            return [
                                'label' => $label,
                                'cells' => [$asNum(0), $asNum(0), $asNum(0), $asNum(0), $asNum(0)],
                            ];
                        }

                        // TOTAL = base - descuento bonus + ISVs
                        $total = $base - $bonusDesc + $isv18 + $isv15;

                        return [
                            'label' => $label,
                            'cells' => [
                                $asNum($base),
                                $asNum(0),       // DESCUENTO siempre .00 en Exento/Exonerado
                                $asNum($isv18),
                                $asNum($isv15),
                                $asNum($total),
                            ],
                        ];
                    };

                    $rowExento = $renderImportRow(
                        'Importe Exento &nbsp; L',
                        $invoice->importe_excento ?? 0,
                        $invoice->importe_exento_isv18 ?? 0,
                        $invoice->importe_exento_isv15 ?? 0,
                        $bonusDescExento,
                    );
                    $rowExonerado = $renderImportRow(
                        'Importe Exonerado L',
                        $invoice->importe_exonerado ?? 0,
                        $invoice->importe_exonerado_isv18 ?? 0,
                        $invoice->importe_exonerado_isv15 ?? 0,
                        0.0,  // En la práctica no hay bonus exonerado; queda en cero
                    );

                    // Fila Gravado — la DESCUENTO viene de ImporteGravado_Desc
                    // (que sí refleja el descuento del bonus gravado en el JSON,
                    // a diferencia de Exento_Desc que no coincide con lo impreso).
                    $gravadoBase = (float) ($invoice->importe_gravado ?? 0);
                    $gravadoDesc = (float) ($invoice->importe_gravado_desc ?? 0);
                    $gravadoIsv18 = (float) ($invoice->importe_gravado_isv18 ?? 0);
                    $gravadoIsv15 = (float) ($invoice->importe_gravado_isv15 ?? 0);
                    $gravadoTotal = (float) ($invoice->importe_gravado_total ?? 0);
                @endphp
                <table style="width:100%; font-size:6.5pt;">
                    <tr>
                        <td style="width:30%;">{!! $rowExento['label'] !!}</td>
                        <td style="width:14%; text-align:right;">{{ $rowExento['cells'][0] }}</td>
                        <td style="width:9%; text-align:right;">{{ $rowExento['cells'][1] }}</td>
                        <td style="width:7%; text-align:right;">{{ $rowExento['cells'][2] }}</td>
                        <td style="width:9%; text-align:right;">{{ $rowExento['cells'][3] }}</td>
                        <td style="width:15%; text-align:right; font-weight:bold;">{{ $rowExento['cells'][4] }}</td>
                    </tr>
                    <tr>
                        <td>{!! $rowExonerado['label'] !!}</td>
                        <td style="text-align:right;">{{ $rowExonerado['cells'][0] }}</td>
                        <td style="text-align:right;">{{ $rowExonerado['cells'][1] }}</td>
                        <td style="text-align:right;">{{ $rowExonerado['cells'][2] }}</td>
                        <td style="text-align:right;">{{ $rowExonerado['cells'][3] }}</td>
                        <td style="text-align:right; font-weight:bold;">{{ $rowExonerado['cells'][4] }}</td>
                    </tr>
                    <tr>
                        <td>Importe Gravado &nbsp; L</td>
                        <td style="text-align:right;">{{ $asNum($gravadoBase) }}</td>
                        <td style="text-align:right;">{{ $gravadoBase == 0.0 ? $asNum(0) : ($gravadoDesc == 0.0 ? $asNum(0) : $asDiscount($gravadoDesc)) }}</td>
                        <td style="text-align:right;">{{ $asNum($gravadoBase == 0.0 ? 0 : $gravadoIsv18) }}</td>
                        <td style="text-align:right;">{{ $asNum($gravadoBase == 0.0 ? 0 : $gravadoIsv15) }}</td>
                        <td style="text-align:right; font-weight:bold;">{{ $asNum($gravadoBase == 0.0 ? 0 : $gravadoTotal) }}</td>
                    </tr>
                    <tr style="border-top:1px solid #000; font-weight:bold;">
                        <td>TOTAL A PAGAR &nbsp; L</td>
                        <td style="text-align:right;">{{ $asNum(($invoice->importe_excento ?? 0) + ($invoice->importe_exonerado ?? 0) + ($invoice->importe_gravado ?? 0)) }}</td>
                        <td style="text-align:right;">{{ $asDiscount($descuentoTotal) }}</td>
                        <td style="text-align:right;">{{ $asNum($invoice->isv18 ?? 0) }}</td>
                        <td style="text-align:right;">{{ $asNum($invoice->isv15 ?? 0) }}</td>
                        <td style="text-align:right; font-weight:bold;">{{ $asNum($invoice->total) }}</td>
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

    {{-- RANGO + COPIA + JAMERARI --}}
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

    {{-- CLÁUSULAS LEGALES JAREMAR --}}
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