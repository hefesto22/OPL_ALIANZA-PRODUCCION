<?php

namespace App\Services\Escp;

use App\Helpers\NumberHelper;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * Genera el flujo ESC/P (bytes) de una o varias facturas para impresión
 * directa en matriz de punto Epson LX-350 (9 agujas, carro angosto).
 *
 * Por qué ESC/P y no HTML→navegador:
 *   - Usa la fuente RESIDENTE del printer → nítida y rápida, no un gráfico
 *     rasterizado que la 9 agujas no resuelve a 5-6pt.
 *   - Con emphasized (ESC E) + double-strike (ESC G) la impresora golpea
 *     cada carácter dos veces → texto oscuro AUNQUE la cinta esté gastada.
 *
 * El sistema viejo (C#) imprimía así: papel blanco continuo, TODO en texto
 * ESC/P (formato AS400). Esto lo replica. El layout va en CONDENSADA para
 * que el formato ancho calce en 8". Toda la geometría es configurable en
 * config/escp.php — se afina tras la prueba física sin tocar código.
 *
 * Dos salidas desde el MISMO layout (garantiza WYSIWYG):
 *   - build()       → bytes ESC/P (lo que se manda a la impresora).
 *   - previewText() → texto plano (lo que se muestra en pantalla).
 *
 * Ver memory project_invoice_print_dotmatrix.
 */
class EscpInvoiceService
{
    private const ESC = "\x1B";

    private const SI = "\x0F";   // condensada ON

    private const FF = "\x0C";   // form feed

    private int $cpl;

    public function __construct()
    {
        $this->cpl = max(40, (int) config('escp.chars_per_line', 136));
    }

    /**
     * Flujo ESC/P completo (bytes) para impresión.
     *
     * @param  Collection<int, Invoice>  $invoices  Con 'lines' precargadas.
     */
    public function build(Collection $invoices): string
    {
        $out = $this->preamble();

        $last = $invoices->count() - 1;
        foreach ($invoices->values() as $i => $invoice) {
            foreach ($this->layoutInvoice($invoice) as $line) {
                $out .= $this->encode($line)."\r\n";
            }

            if (config('escp.form_feed_between_invoices', true)) {
                $out .= self::FF;
            } elseif ($i !== $last) {
                $out .= "\r\n";
            }
        }

        $out .= self::ESC.'@'; // reset final

        return $out;
    }

    /**
     * Texto plano del MISMO layout, para la vista previa en pantalla.
     * Separa cada factura con una línea de corte para representar el FF.
     *
     * @param  Collection<int, Invoice>  $invoices
     */
    public function previewText(Collection $invoices): string
    {
        $blocks = [];
        foreach ($invoices->values() as $invoice) {
            $lines = array_map(fn ($l) => $this->encode($l), $this->layoutInvoice($invoice));
            $blocks[] = implode("\n", $lines);
        }

        return implode("\n".str_repeat('=', $this->cpl).' [CORTE DE PÁGINA] '.PHP_EOL, $blocks);
    }

    /**
     * Secuencia de inicialización: calidad, fuente, paso, oscurecido y
     * largo de página. Es lo que garantiza "imprime lo mejor posible".
     */
    private function preamble(): string
    {
        $s = self::ESC.'@'; // reset

        // Calidad: LQ (ESC x 1) o draft (ESC x 0)
        $s .= self::ESC.'x'.(config('escp.quality', 'lq') === 'draft' ? "\x00" : "\x01");

        // Fuente LQ residente (ESC k n): 0 Roman, 1 Sans Serif
        $s .= self::ESC.'k'.chr(max(0, (int) config('escp.font', 0)));

        // Paso de caracteres
        $pitch = config('escp.pitch', 'condensed');
        if ($pitch === '12cpi') {
            $s .= self::ESC.'M';
        } else {
            $s .= self::ESC.'P';
            if ($pitch === 'condensed') {
                $s .= self::SI;
            }
        }

        // Oscurecido (clave con cinta gastada): doble golpe
        if (config('escp.emphasized', true)) {
            $s .= self::ESC.'E';
        }
        if (config('escp.double_strike', true)) {
            $s .= self::ESC.'G';
        }

        // Interlineado 1/6" (6 lpi)
        $s .= self::ESC.'2';

        // Largo de página en líneas (ESC C n)
        $lines = max(1, min(127, (int) config('escp.page_length_lines', 66)));
        $s .= self::ESC.'C'.chr($lines);

        // Margen izquierdo (ESC l n)
        $left = max(0, (int) config('escp.left_margin', 0));
        if ($left > 0) {
            $s .= self::ESC.'l'.chr($left);
        }

        return $s;
    }

    /**
     * Líneas de texto (sin control) de una factura, maquetadas en columnas
     * fijas dentro del presupuesto de ancho.
     *
     * @return string[]
     */
    private function layoutInvoice(Invoice $invoice): array
    {
        $L = [];
        $w = $this->cpl;

        // ── Encabezado emisor + número de factura ──────────────────────
        $L[] = $this->lr('RTN: 08019017952895', 'FACTURA  NT '.$invoice->invoice_number, $w);
        $L[] = $this->lr('BO. LA GUADALUPE, CL. LAS ACACIAS, M.D.C. HONDURAS',
            'Fecha: '.$this->date($invoice->invoice_date), $w);
        $L[] = $this->lr('TEL: 2238-2484  2561-7410   MATRIZ',
            'C.A.I '.($invoice->cai ?? ''), $w);
        $L[] = 'KM 15 CARRETERA A BUFALO, VILLANUEVA CORTES, HONDURAS';
        $L[] = 'finanzas@jaremar.com           COPIA: OBLIGADO TRIBUTARIO EMISOR';
        $L[] = str_repeat('-', $w);

        // ── Datos de cliente ───────────────────────────────────────────
        $L[] = $this->lr('Facturado: '.$invoice->client_name,
            'Cliente No: '.$invoice->client_id.'   Ruta: '.$invoice->route_number, $w);
        $municipality = strtoupper(trim((string) ($invoice->municipality ?? '')));
        $department = strtoupper(trim((string) ($invoice->department ?? '')));
        $loc = $municipality !== '' && $department !== ''
            ? "{$municipality} DEPARTAMENTO DE {$department} HONDURAS"
            : trim("{$municipality} {$department}");
        $L[] = 'RTN: '.($invoice->client_rtn ?? '').'   Entregar a: '.($invoice->deliver_to ?? $invoice->client_name);
        $L[] = 'Direccion: '.trim(($invoice->neighborhood ?? '').' '.$loc.' '.($invoice->address ?? ''));
        $L[] = 'Moneda: LEMPIRAS   Vendedor: '.str_pad((string) ($invoice->seller_id ?? ''), 6, '0', STR_PAD_LEFT)
            .'   Pedido: '.($invoice->order_number ?? '').'   Cond: CONTADO';
        $L[] = str_repeat('-', $w);

        // ── Tabla de productos ─────────────────────────────────────────
        $cols = [
            ['ARTICULO', 10, 'L'],
            ['DESCRIPCION', 30, 'L'],
            ['UM', 2, 'L'],
            ['CJ', 4, 'R'],
            ['UN', 4, 'R'],
            ['CANT', 7, 'R'],
            ['PRECIO', 10, 'R'],
            ['VALOR', 10, 'R'],
            ['DESCTO', 8, 'R'],
            ['18%', 6, 'R'],
            ['15%', 7, 'R'],
            ['TOTAL', 10, 'R'],
        ];
        $L[] = $this->row(array_map(fn ($c) => [$c[0], $c[1], $c[2]], $cols));
        $L[] = str_repeat('-', $w);

        foreach ($invoice->lines as $line) {
            [$valor, $descuento, $isv15, $isv18, $total] = $this->lineNumbers($line);
            $isCj = strtoupper((string) $line->unit_sale) === 'CJ';
            $L[] = $this->row([
                [$line->product_id, 10, 'L'],
                [$line->product_description, 30, 'L'],
                [(string) $line->unit_sale, 2, 'L'],
                [$isCj ? number_format((float) $line->quantity_box, 0) : '', 4, 'R'],
                [! $isCj ? number_format((float) $line->quantity_fractions, 0) : '', 4, 'R'],
                [NumberHelper::as400((float) $line->quantity_decimal, 3), 7, 'R'],
                [NumberHelper::as400((float) $line->price, 3), 10, 'R'],
                [NumberHelper::as400($valor, 2), 10, 'R'],
                [NumberHelper::as400($descuento, 2), 8, 'R'],
                [NumberHelper::as400($isv18, 2), 6, 'R'],
                [NumberHelper::as400($isv15, 2), 7, 'R'],
                [NumberHelper::as400($total, 2), 10, 'R'],
            ]);
        }

        $L[] = str_repeat('-', $w);

        // ── Totales ────────────────────────────────────────────────────
        $L[] = $this->lr('', 'IMPORTE GRAVADO  L '.NumberHelper::as400((float) ($invoice->importe_gravado ?? 0), 2), $w);
        $L[] = $this->lr('', 'ISV 15%          L '.NumberHelper::as400((float) ($invoice->isv15 ?? 0), 2), $w);
        $L[] = $this->lr('', 'ISV 18%          L '.NumberHelper::as400((float) ($invoice->isv18 ?? 0), 2), $w);
        $L[] = $this->lr('', 'TOTAL A PAGAR    L '.NumberHelper::as400((float) $invoice->total, 2), $w);
        $L[] = 'SON: '.strtoupper(NumberHelper::toWords((float) $invoice->total)).' LEMPIRAS';
        $L[] = 'Rango Autorizado: '.($invoice->range_start ?? '').' Al '.($invoice->range_end ?? '');
        $L[] = str_repeat('-', $w);

        // ── Firmas ─────────────────────────────────────────────────────
        $L[] = '';
        $L[] = '_______________________________     _______________________________';
        $L[] = 'NOMBRE COMPLETO                     NO. DE IDENTIFICACION';
        $L[] = '';
        $L[] = '_______________________________';
        $L[] = 'FIRMA DE RECIBIDO';
        $L[] = str_repeat('-', $w);

        // ── Cláusulas legales ──────────────────────────────────────────
        $clausulas = [
            '1.- LAS FACTURAS Y NOTAS DE DEBITO PAGADAS CON CHEQUE SE CONSIDERAN CANCELADAS AL ACEPTAR EL BANCO EL CHEQUE. SI ES RECHAZADO, LA FACTURA ENTRA EN MORA Y EL CLIENTE SUFRAGA LOS GASTOS.',
            '2.- LAS FACTURAS NO CANCELADAS EN EL PLAZO PACTADO TENDRAN RECARGO SOBRE SALDO EN MORA SEGUN LA TASA DE INTERES DEL MERCADO BANCARIO.',
            '3.- TODA FACTURA AL CREDITO O NOTA DE DEBITO NO SE CONSIDERA CANCELADA SI NO ES CON RECIBO EN CAJA.',
            '4.- TODA NOTA DE CREDITO DEBERA APLICARSE EN UN PLAZO MAXIMO DE TRES MESES DESDE LA FECHA DE EMISION.',
        ];
        foreach ($clausulas as $c) {
            foreach ($this->wrap($c, $w) as $wl) {
                $L[] = $wl;
            }
        }

        return $L;
    }

    /**
     * valor/descuento/isv/total de una línea, replicando la lógica de bonus
     * (tipo B) del Blade (ver invoice-pdf.blade.php).
     *
     * @return array{0:float,1:float,2:float,3:float,4:float} [valor, descuento, isv15, isv18, total]
     */
    private function lineNumbers(object $line): array
    {
        if (strtoupper((string) ($line->product_type ?? '')) === 'B') {
            $exact = (float) $line->price * (float) $line->quantity_decimal;
            $valor = floor($exact * 100) / 100;
            $descuento = -$valor;
            $hasTax = ((float) ($line->tax_percent ?? 0)) > 0;
            $isv15 = $hasTax ? round($exact - $valor, 2) : 0.0;

            return [$valor, $descuento, $isv15, 0.0, $isv15];
        }

        return [
            (float) $line->subtotal,
            -abs((float) ($line->discount ?? 0)),
            (float) ($line->tax ?? 0),
            (float) ($line->tax18 ?? 0),
            (float) $line->total,
        ];
    }

    // ── Helpers de maquetado ───────────────────────────────────────────

    /**
     * @param  array<int, array{0:string,1:int,2:string}>  $cells  [texto, ancho, align L|R]
     */
    private function row(array $cells): string
    {
        $parts = [];
        foreach ($cells as [$text, $width, $align]) {
            $parts[] = $this->col((string) $text, (int) $width, (string) $align);
        }

        return $this->fit(implode(' ', $parts));
    }

    private function col(string $text, int $width, string $align): string
    {
        $text = $this->clean($text);
        if (mb_strlen($text) > $width) {
            $text = mb_substr($text, 0, $width);
        }

        return $align === 'R'
            ? str_pad($text, $width, ' ', STR_PAD_LEFT)
            : str_pad($text, $width, ' ', STR_PAD_RIGHT);
    }

    private function lr(string $left, string $right, int $width): string
    {
        $left = $this->clean($left);
        $right = $this->clean($right);
        $space = $width - mb_strlen($left) - mb_strlen($right);
        if ($space < 1) {
            return $this->fit($left.' '.$right);
        }

        return $left.str_repeat(' ', $space).$right;
    }

    private function fit(string $line): string
    {
        return mb_strlen($line) > $this->cpl ? mb_substr($line, 0, $this->cpl) : $line;
    }

    /**
     * @return string[]
     */
    private function wrap(string $text, int $width): array
    {
        $text = $this->clean($text);
        $out = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            if ($line === '') {
                $line = $word;
            } elseif (mb_strlen($line.' '.$word) <= $width) {
                $line .= ' '.$word;
            } else {
                $out[] = $line;
                $line = $word;
            }
        }
        if ($line !== '') {
            $out[] = $line;
        }

        return $out;
    }

    private function date(mixed $date): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($date)->format('Y/m/d');
        } catch (\Throwable) {
            return '';
        }
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Translitera a ASCII si está configurado, para no depender del code
     * page del printer (evita ñ/acentos garabateados).
     */
    private function encode(string $line): string
    {
        if (! config('escp.ascii_transliterate', true)) {
            return $line;
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $line);
        if ($ascii === false) {
            $ascii = preg_replace('/[^\x20-\x7E]/', '', $line) ?? $line;
        }

        return preg_replace('/[^\x20-\x7E]/', '', $ascii) ?? $ascii;
    }
}
