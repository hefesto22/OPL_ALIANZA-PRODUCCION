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

    /**
     * Anchos FIJOS de las columnas de cantidad/codigo/dinero de la tabla, en
     * orden: Cj, Und, Codigo, P.Unit, SubT, Imp, Total. Descripcion NO va aqui:
     * absorbe el ancho restante de la linea (ver descriptionWidth()).
     *
     * SubT y Total = 10 → sostienen montos de hasta 121,050.00 (10 chars) sin
     * truncar (regresion cubierta por test_large_amounts_are_not_truncated).
     */
    private const COL_WIDTHS = [2, 3, 8, 9, 10, 9, 10];

    /** Piso de ancho de la descripcion, aunque cpl sea muy chico. */
    private const DESC_MIN_WIDTH = 12;

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
            foreach ($this->layoutInvoicePages($invoice) as $pageLines) {
                // Modo dynamic (papel blanco): largo de página = lo que ocupa
                // la página. Modo fixed (papel perforado): el largo ya quedó
                // fijado en el preamble = la forma, así el FF cae en la
                // perforación de cada forma.
                if (! $fixed) {
                    $pageLen = min(127, max(1, count($pageLines) + $margin));
                    $out .= self::ESC.'C'.chr($pageLen);
                }

                foreach ($pageLines as $line) {
                    $out .= $this->encode($line)."\r\n";
                }

                // FF avanza al final de la forma → la auto tear-off corta ahí.
                // Ahora hay un FF por PÁGINA: una factura larga ocupa varias
                // formas, cada una con corte limpio en su perforación.
                $out .= self::FF;
            }
        }

        $out .= self::ESC.'@';

        return $out;
    }

    /**
     * Igual que build() pero con preámbulo ENDURECIDO: fuerza explícitamente
     * todos los parámetros que pueden variar entre unidades LX-350 (espaciado
     * extra, tabla de caracteres, margen derecho, dirección de impresión), de
     * modo que dos impresoras del mismo modelo con defaults distintos impriman
     * idéntico SIN tocar el panel. Lo único que NO se puede forzar por software
     * es la emulación (ESC/P vs IBM) — eso es del panel.
     *
     * Botón de PRUEBA separado: no reemplaza a build() hasta validar en físico.
     *
     * @param  Collection<int, Invoice>  $invoices  Con 'lines' precargadas.
     */
    public function buildHardened(Collection $invoices): string
    {
        $out = $this->preambleHardened();
        $fixed = config('escp.form_mode', 'fixed') === 'fixed';
        $margin = max(0, (int) config('escp.bottom_margin_lines', 2));

        foreach ($invoices->values() as $invoice) {
            foreach ($this->layoutInvoicePages($invoice) as $pageLines) {
                if (! $fixed) {
                    $pageLen = min(127, max(1, count($pageLines) + $margin));
                    $out .= self::ESC.'C'.chr($pageLen);
                }

                foreach ($pageLines as $line) {
                    $out .= $this->encode($line)."\r\n";
                }

                $out .= self::FF;
            }
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
        $corte = "\n".str_repeat('-', $this->cpl).' ✂ CORTE '.PHP_EOL;

        $blocks = [];
        foreach ($invoices->values() as $invoice) {
            $pageTexts = [];
            foreach ($this->layoutInvoicePages($invoice) as $pageLines) {
                $pageTexts[] = implode("\n", array_map(fn ($l) => $this->encode($l), $pageLines));
            }
            // Un ✂ CORTE entre páginas de la MISMA factura (cada forma se corta)
            // y también entre facturas distintas.
            $blocks[] = implode($corte, $pageTexts);
        }

        return implode($corte, $blocks);
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
     * Preámbulo ENDURECIDO: fuerza todo lo que puede diferir entre unidades.
     * Mismos parámetros base que preamble() + comandos extra que igualan el
     * resultado aunque cada impresora traiga defaults propios.
     */
    private function preambleHardened(): string
    {
        $s = self::ESC.'@';                  // reset a defaults de la unidad
        $s .= self::ESC.'U'.chr(1);          // impresión unidireccional → columnas alineadas igual

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

        $s .= self::ESC.' '.chr(0);          // ESC SP 0: cero espaciado extra entre caracteres (evita texto "más ancho")
        $s .= self::ESC.'R'.chr(0);          // juego internacional = USA (fijo)
        $s .= self::ESC.'t'.chr(1);          // tabla de caracteres gráfica (fija)

        if (config('escp.emphasized', true)) {
            $s .= self::ESC.'E';
        }
        if (config('escp.double_strike', true)) {
            $s .= self::ESC.'G';
        }

        $s .= config('escp.line_spacing', '8lpi') === '6lpi' ? self::ESC.'2' : self::ESC.'0';

        if (config('escp.form_mode', 'fixed') === 'fixed') {
            $len = max(1, min(127, (int) config('escp.page_length_lines', 38)));
            $s .= self::ESC.'C'.chr($len);
        }

        $left = max(0, (int) config('escp.left_margin', 0));
        if ($left > 0) {
            $s .= self::ESC.'l'.chr($left);
        }

        // Margen derecho fijo = margen izq + ancho de línea. Si una unidad trae
        // un margen derecho propio más angosto, sin esto envolvería/cortaría el
        // texto. Lo fijamos para que el ancho de línea sea idéntico en todas.
        $right = min(255, $left + $this->cpl + 1);
        $s .= self::ESC.'Q'.chr($right);

        return $s;
    }

    /**
     * Formato Hosana de una factura, PAGINADO en formas físicas.
     *
     * Si la factura cabe en una sola forma, devuelve UNA página (salida idéntica
     * a la histórica). Si no cabe (facturas de muchos productos), la parte en
     * varias formas repitiendo en cada una el encabezado del emisor + los
     * títulos de columna + "Pagina X de Y", con los totales/firmas solo en la
     * última. Así una factura larga NO se amontona ni imprime sobre el doblez:
     * cada forma sale limpia y se corta en su perforación (un FF por página).
     *
     * @return string[][] Lista de páginas; cada página es una lista de líneas.
     */
    private function layoutInvoicePages(Invoice $invoice): array
    {
        $emisor = $this->emisorLines($invoice);
        $meta = $this->metaLines($invoice);
        $tableHead = $this->tableHeaderLines();
        $items = $this->itemRows($invoice);
        $footer = $this->footerLines($invoice);

        $fixed = config('escp.form_mode', 'fixed') === 'fixed';
        $usable = max(
            1,
            (int) config('escp.page_length_lines', 44) - (int) config('escp.bottom_margin_lines', 2)
        );

        // Cabe en una forma (o es papel blanco continuo): una sola página SIN
        // indicador de página → salida byte-idéntica a la histórica.
        $single = count($emisor) + count($meta) + count($tableHead) + count($items) + count($footer);
        if (! $fixed || $single <= $usable) {
            return [array_merge($emisor, $meta, $tableHead, $items, $footer)];
        }

        // No cabe: paginar. Cada forma repite emisor + indicador + meta + títulos.
        $perPageHeader = count($emisor) + 1 + count($meta) + count($tableHead);
        $bodyCap = max(1, $usable - $perPageHeader);   // items por forma intermedia
        $footerH = count($footer);
        $lastCap = max(1, $bodyCap - $footerH);         // items en la forma final (lleva totales)
        $n = count($items);

        $pageCount = $n <= $lastCap ? 1 : 1 + (int) ceil(($n - $lastCap) / $bodyCap);
        $pageCount = max(2, $pageCount);                // llegamos aquí porque NO cabía en 1

        // Reparto equilibrado: la última forma hasta lastCap; las previas parejas.
        $lastItems = min($lastCap, (int) ceil($n / $pageCount));
        $earlierPages = $pageCount - 1;
        $earlierPer = $earlierPages > 0 ? (int) ceil(($n - $lastItems) / $earlierPages) : 0;

        $pages = [];
        $idx = 0;
        for ($p = 1; $p <= $pageCount; $p++) {
            $take = $p < $pageCount ? min($earlierPer, $n - $idx) : ($n - $idx);
            $slice = array_slice($items, $idx, $take);
            $idx += $take;

            $indicator = $this->lr('', 'Pagina '.$p.' de '.$pageCount, $this->cpl);
            $pages[] = array_merge(
                $emisor,
                [$indicator],
                $meta,
                $tableHead,
                $slice,
                $p === $pageCount ? $footer : []
            );
        }

        return $pages;
    }

    /**
     * Encabezado del emisor + fila de correlativos (se repite en cada forma).
     *
     * @return string[]
     */
    private function emisorLines(Invoice $invoice): array
    {
        return [
            $this->center('GRUPO JAREMAR DE HONDURAS S.A. DE C.V.'),
            $this->center('Bo: La Guadalupe Cl: Las Acacias Apto:13 Edif: Italia M.D.C. F.M. Honduras - Matriz'),
            $this->center('Tel: 2238-2484/2561-7410   RTN: 08019017952895   No. Guia Remision: '.($invoice->manifest->number ?? '')),
            $this->center('Correo: finanzas@jaremar.com   Sucursal: KM 15 Carret. a Bufalo Villanueva CTS HN'),
            $this->center('Tel: 2561-7410/2561-7411   No. G. Rem.: '.($invoice->manifest->number ?? '')),
            $this->center('CAI: '.($invoice->cai ?? '')),
            $this->center('Rango autorizado: '.($invoice->range_start ?? '').' Al '.($invoice->range_end ?? '')),
            $this->row([
                ['No. Corr. OCE:', 34, 'L'],
                ['No. Corr. CRE:', 23, 'L'],
                ['No. Ident. Reg. S.A.G.:', 23, 'L'],
            ]),
        ];
    }

    /**
     * Datos de factura, cliente y dirección (se repiten en cada forma).
     *
     * @return string[]
     */
    private function metaLines(Invoice $invoice): array
    {
        $w = $this->cpl;
        $L = [];

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

        return $L;
    }

    /**
     * Encabezado de la tabla (separadores + títulos), se repite en cada forma.
     * Anchos FIJOS de cantidad/codigo/dinero; Descripcion ABSORBE el resto del
     * ancho de línea (cpl) → nombres largos ("ORISOL BOLSC/V 700 mL MAYOREO1/20")
     * ya no se recortan. Al derivarse de cpl, se auto-ajusta por env.
     *
     * @return string[]
     */
    private function tableHeaderLines(): array
    {
        [$wCj, $wUnd, $wCod, $wPU, $wSub, $wImp, $wTot] = self::COL_WIDTHS;

        return [
            str_repeat('-', $this->cpl),
            $this->row([
                ['Cj', $wCj, 'L'], ['Und', $wUnd, 'L'], ['Codigo', $wCod, 'L'], ['Descripcion', $this->descriptionWidth(), 'L'],
                ['P.Unit', $wPU, 'R'], ['SubT', $wSub, 'R'], ['Imp', $wImp, 'R'], ['Total', $wTot, 'R'],
            ]),
            str_repeat('-', $this->cpl),
        ];
    }

    /**
     * Una fila por línea de la factura (sin separadores).
     *
     * @return string[]
     */
    private function itemRows(Invoice $invoice): array
    {
        [$wCj, $wUnd, $wCod, $wPU, $wSub, $wImp, $wTot] = self::COL_WIDTHS;
        $wDesc = $this->descriptionWidth();
        $rows = [];

        foreach ($invoice->lines as $line) {
            $imp = (float) ($line->tax ?? 0) + (float) ($line->tax18 ?? 0);
            $rows[] = $this->row([
                [number_format((float) $line->quantity_box, 0), $wCj, 'L'],
                [number_format((float) $line->quantity_fractions, 0), $wUnd, 'L'],
                [(string) $line->product_id, $wCod, 'L'],
                [(string) $line->product_description, $wDesc, 'L'],
                [number_format((float) $line->price, 2), $wPU, 'R'],
                [number_format((float) $line->subtotal, 2), $wSub, 'R'],
                [number_format($imp, 2), $wImp, 'R'],
                [number_format((float) $line->total, 2), $wTot, 'R'],
            ]);
        }

        return $rows;
    }

    /**
     * Cierre de tabla + totales + SON + firmas (solo en la última forma).
     *
     * @return string[]
     */
    private function footerLines(Invoice $invoice): array
    {
        $w = $this->cpl;

        $sub = (float) ($invoice->importe_gravado ?? 0) + (float) ($invoice->importe_excento ?? 0) + (float) ($invoice->importe_exonerado ?? 0);

        return [
            str_repeat('-', $w),
            $this->lr('', 'SubTotal:      L. '.number_format($sub, 2), $w),
            $this->lr('', 'Impuesto 18%:  L. '.number_format((float) ($invoice->isv18 ?? 0), 2), $w),
            $this->lr('', 'Impuesto 15%:  L. '.number_format((float) ($invoice->isv15 ?? 0), 2), $w),
            $this->lr('', 'TOTAL:         L. '.number_format((float) $invoice->total, 2), $w),
            'SON: '.strtoupper(NumberHelper::toWords((float) $invoice->total)),
            '',
            '',
            $this->row([
                ['______________', 20, 'C'],
                ['______________', 20, 'C'],
                ['______________', 20, 'C'],
            ]),
            $this->row([
                ['Nombre Completo', 20, 'C'],
                ['No. Identificacion', 20, 'C'],
                ['Firma de Recibido', 20, 'C'],
            ]),
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Ancho de la columna Descripcion = ancho de linea (cpl) menos las columnas
     * fijas y los espacios que las separan. Las 8 columnas se unen con 1 espacio
     * (row()), o sea 7 separadores = count(COL_WIDTHS). Con floor de seguridad.
     */
    private function descriptionWidth(): int
    {
        $fixed = array_sum(self::COL_WIDTHS);
        $gaps = count(self::COL_WIDTHS);

        return max(self::DESC_MIN_WIDTH, $this->cpl - $fixed - $gaps);
    }

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
