<?php

namespace App\Services\Escp;

use App\Helpers\NumberHelper;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * Genera el flujo ESC/P (bytes) del FORMATO HOSANA para impresión directa en
 * matriz de punto Epson LX-350.
 *
 * Réplica de la factura de texto del sistema viejo de Jaremar: layout simple
 * (8 columnas), fuente residente grande y legible. Texto puro (sin gráficos)
 * → nítido, oscuro (emphasized + double-strike) y rápido.
 *
 * CLAVE — largo de página DINÁMICO: antes de cada factura se fija el largo de
 * página (ESC C n) en exactamente las líneas que ocupa esa factura. Tras
 * imprimirla, el form feed avanza justo al final del texto, donde la auto
 * tear-off de la LX-350 corta. Así NO se imprime espacio blanco de más y cada
 * factura sale continua e individual. (El gráfico/Ctrl+P no puede esto: el
 * navegador siempre manda hojas de tamaño fijo.)
 *
 * Dos salidas del MISMO layout (WYSIWYG):
 *   - build()       → bytes ESC/P (a la impresora).
 *   - previewText() → texto plano (a la pantalla).
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
        $this->cpl = max(40, (int) config('escp.chars_per_line', 80));
    }

    /**
     * Flujo ESC/P completo (bytes) con largo de página dinámico por factura.
     *
     * @param  Collection<int, Invoice>  $invoices  Con 'lines' precargadas.
     */
    public function build(Collection $invoices): string
    {
        $out = $this->preamble();
        $fixed = config('escp.form_mode', 'fixed') === 'fixed';
        $margin = max(0, (int) config('escp.bottom_margin_lines', 2));

        foreach ($invoices->values() as $invoice) {
            $lines = $this->layoutInvoice($invoice);

            // Modo dynamic (papel blanco): largo de página = lo que ocupa la
            // factura. Modo fixed (papel perforado): el largo ya quedó fijado
            // en el preamble = la forma, así el FF cae en la perforación.
            if (! $fixed) {
                $pageLen = min(127, max(1, count($lines) + $margin));
                $out .= self::ESC.'C'.chr($pageLen);
            }

            foreach ($lines as $line) {
                $out .= $this->encode($line)."\r\n";
            }

            // FF avanza al final de la forma → la auto tear-off corta ahí.
            $out .= self::FF;
        }

        $out .= self::ESC.'@';

        return $out;
    }

    /**
     * Texto plano del MISMO layout para la vista previa.
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

        return implode("\n".str_repeat('-', $this->cpl).' ✂ CORTE '.PHP_EOL, $blocks);
    }

    /**
     * Inicialización global (calidad, fuente, paso, oscurecido, interlineado).
     * El largo de página NO se fija aquí: es dinámico por factura en build().
     */
    private function preamble(): string
    {
        $s = self::ESC.'@';
        $s .= self::ESC.'x'.(config('escp.quality', 'lq') === 'draft' ? "\x00" : "\x01");
        $s .= self::ESC.'k'.chr(max(0, (int) config('escp.font', 0)));

        $pitch = config('escp.pitch', '12cpi');
        if ($pitch === '12cpi') {
            $s .= self::ESC.'M';
        } else {
            $s .= self::ESC.'P';
            if ($pitch === 'condensed') {
                $s .= self::SI;
            }
        }

        if (config('escp.emphasized', true)) {
            $s .= self::ESC.'E';
        }
        if (config('escp.double_strike', true)) {
            $s .= self::ESC.'G';
        }

        // Interlineado: 8 lpi (ESC 0, compacto) o 6 lpi (ESC 2).
        $s .= config('escp.line_spacing', '8lpi') === '6lpi' ? self::ESC.'2' : self::ESC.'0';

        // Modo forma fija (papel perforado): largo de página = la forma.
        if (config('escp.form_mode', 'fixed') === 'fixed') {
            $len = max(1, min(127, (int) config('escp.page_length_lines', 38)));
            $s .= self::ESC.'C'.chr($len);
        }

        $left = max(0, (int) config('escp.left_margin', 0));
        if ($left > 0) {
            $s .= self::ESC.'l'.chr($left);
        }

        return $s;
    }

    /**
     * Líneas del Formato Hosana de una factura.
     *
     * @return string[]
     */
    private function layoutInvoice(Invoice $invoice): array
    {
        $w = $this->cpl;
        $L = [];

        // ── Encabezado emisor (centrado) ───────────────────────────────
        $L[] = $this->center('GRUPO JAREMAR DE HONDURAS S.A. DE C.V.');
        $L[] = $this->center('Bo: La Guadalupe Cl: Las Acacias Apto:13 Edif: Italia M.D.C. F.M. Honduras - Matriz');
        $L[] = $this->center('Tel: 2238-2484/2561-7410   RTN: 08019017952895   No. Guia Remision: '.($invoice->manifest->number ?? ''));
        $L[] = $this->center('Correo: finanzas@jaremar.com   Sucursal: KM 15 Carret. a Bufalo Villanueva CTS HN');
        $L[] = $this->center('Tel: 2561-7410/2561-7411   No. G. Rem.: '.($invoice->manifest->number ?? ''));
        $L[] = $this->center('CAI: '.($invoice->cai ?? ''));
        $L[] = $this->center('Rango autorizado: '.($invoice->range_start ?? '').' Al '.($invoice->range_end ?? ''));
        $L[] = $this->row([
            ['No. Corr. OCE:', 34, 'L'],
            ['No. Corr. CRE:', 23, 'L'],
            ['No. Ident. Reg. S.A.G.:', 23, 'L'],
        ]);

        // ── Factura / Cliente ──────────────────────────────────────────
        $L[] = 'Factura: '.$invoice->invoice_number
            .'   Fecha: '.$this->date($invoice->invoice_date)
            .'   Limite: '.$this->date($invoice->print_limit_date);
        $L[] = 'Cliente: '.$invoice->client_id.'-'.$invoice->client_name
            .'   RTN: '.($invoice->client_rtn ?? '')
            .'   Pago: '.($invoice->payment_type ?? 'CONTADO')
            .'   Ruta: '.$invoice->route_number;

        $municipality = strtoupper(trim((string) ($invoice->municipality ?? '')));
        $department = strtoupper(trim((string) ($invoice->department ?? '')));
        $loc = $municipality !== '' && $department !== ''
            ? "{$municipality} DEPARTAMENTO DE {$department} HONDURAS"
            : trim("{$municipality} {$department}");
        $L[] = 'Direccion:';
        foreach ($this->wrap(trim(($invoice->address ?? '').' '.$loc), $w) as $dl) {
            $L[] = $dl;
        }

        // ── Tabla ──────────────────────────────────────────────────────
        $L[] = str_repeat('-', $w);
        // Anchos pensados para que montos grandes (hasta 9,999,999.99) NO se
        // trunquen: las columnas de dinero son anchas; Descripcion cede espacio
        // (el Codigo identifica el producto). La fila suma exactamente 80 col.
        $L[] = $this->row([
            ['Cj', 2, 'L'], ['Und', 3, 'L'], ['Codigo', 8, 'L'], ['Descripcion', 20, 'L'],
            ['P.Unit', 9, 'R'], ['SubT', 11, 'R'], ['Imp', 9, 'R'], ['Total', 11, 'R'],
        ]);
        $L[] = str_repeat('-', $w);

        foreach ($invoice->lines as $line) {
            $imp = (float) ($line->tax ?? 0) + (float) ($line->tax18 ?? 0);
            $L[] = $this->row([
                [number_format((float) $line->quantity_box, 0), 2, 'L'],
                [number_format((float) $line->quantity_fractions, 0), 3, 'L'],
                [(string) $line->product_id, 8, 'L'],
                [(string) $line->product_description, 20, 'L'],
                [number_format((float) $line->price, 2), 9, 'R'],
                [number_format((float) $line->subtotal, 2), 11, 'R'],
                [number_format($imp, 2), 9, 'R'],
                [number_format((float) $line->total, 2), 11, 'R'],
            ]);
        }
        $L[] = str_repeat('-', $w);

        // ── Totales (a la derecha) ─────────────────────────────────────
        $sub = (float) ($invoice->importe_gravado ?? 0) + (float) ($invoice->importe_excento ?? 0) + (float) ($invoice->importe_exonerado ?? 0);
        $L[] = $this->lr('', 'SubTotal:      L. '.number_format($sub, 2), $w);
        $L[] = $this->lr('', 'Impuesto 18%:  L. '.number_format((float) ($invoice->isv18 ?? 0), 2), $w);
        $L[] = $this->lr('', 'Impuesto 15%:  L. '.number_format((float) ($invoice->isv15 ?? 0), 2), $w);
        $L[] = $this->lr('', 'TOTAL:         L. '.number_format((float) $invoice->total, 2), $w);

        $L[] = 'SON: '.strtoupper(NumberHelper::toWords((float) $invoice->total));

        // ── Firmas ─────────────────────────────────────────────────────
        $L[] = '';
        $L[] = '';
        $L[] = $this->row([
            ['______________', 20, 'C'],
            ['______________', 20, 'C'],
            ['______________', 20, 'C'],
        ]);
        $L[] = $this->row([
            ['Nombre Completo', 20, 'C'],
            ['No. Identificacion', 20, 'C'],
            ['Firma de Recibido', 20, 'C'],
        ]);

        return $L;
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function center(string $text): string
    {
        $text = $this->clean($text);
        if (mb_strlen($text) >= $this->cpl) {
            return mb_substr($text, 0, $this->cpl);
        }
        $pad = (int) floor(($this->cpl - mb_strlen($text)) / 2);

        return str_repeat(' ', $pad).$text;
    }

    /**
     * @param  array<int, array{0:string,1:int,2:string}>  $cells  [texto, ancho, align L|R|C]
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
        if ($align === 'R') {
            return str_pad($text, $width, ' ', STR_PAD_LEFT);
        }
        if ($align === 'C') {
            return str_pad($text, $width, ' ', STR_PAD_BOTH);
        }

        return str_pad($text, $width, ' ', STR_PAD_RIGHT);
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

        return $out ?: [''];
    }

    private function date(mixed $date): string
    {
        if (empty($date)) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

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
