<?php

namespace Tests\Feature\Services\Escp;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Manifest;
use App\Services\Escp\EscpInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests del flujo ESC/P del Formato Hosana (Epson LX-350).
 *
 * Verifican calidad/oscurecido, datos, ASCII puro, y la clave del formato:
 * LARGO DE PÁGINA DINÁMICO por factura (un ESC C por factura) + un form feed
 * por factura → corte exacto al final del texto.
 */
class EscpInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private static int $manifestSeq = 770000;

    private function invoiceWithLines(array $overrides = [], int $lines = 2): Invoice
    {
        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);

        $invoice = Invoice::factory()->for($manifest)->create(array_merge([
            'invoice_number' => 'F77000001',
            'client_name' => 'PULPERIA PRUEBA',
            'total' => 1010.04,
            'importe_gravado' => 878.29,
            'isv15' => 131.75,
        ], $overrides));

        InvoiceLine::factory()->count($lines)->for($invoice)->create();
        $invoice->load('lines');

        return $invoice;
    }

    public function test_deliver_to_line_is_printed_when_it_names_a_different_business(): void
    {
        // Caso real: factura 002-001-01-03931792. Jaremar factura a
        // "CONSUMIDOR FINAL" y manda EntregarA = POIT 24/7. Sin esta linea el
        // motorista no tiene forma de saber a que negocio va la mercaderia.
        $invoice = $this->invoiceWithLines([
            'client_name' => '"CONSUMIDOR FINAL"',
            'deliver_to' => 'POIT 24/7',
        ]);

        $out = app(EscpInvoiceService::class)->previewText(collect([$invoice]));

        $this->assertStringContainsString('Entregar a: POIT 24/7', $out);
        // El ente FACTURADO sigue saliendo: es un documento fiscal, la linea
        // nueva se suma, no sustituye.
        $this->assertStringContainsString('CONSUMIDOR FINAL', $out);
    }

    public function test_client_name_is_printed_without_the_quotes_jaremar_sends(): void
    {
        $invoice = $this->invoiceWithLines(['client_name' => '"CONSUMIDOR FINAL"']);

        $out = app(EscpInvoiceService::class)->previewText(collect([$invoice]));

        $this->assertStringContainsString('-CONSUMIDOR FINAL', $out);
        $this->assertStringNotContainsString('"CONSUMIDOR FINAL"', $out);
    }

    public function test_deliver_to_line_is_omitted_when_it_only_repeats_the_client(): void
    {
        // El 98.9% de las facturas de prod. Una linea de las 44 de la forma no
        // se gasta en repetir un dato que ya salio arriba.
        $invoice = $this->invoiceWithLines([
            'client_name' => 'PULPERIA PRUEBA',
            'deliver_to' => 'PULPERIA PRUEBA',
        ]);

        $out = app(EscpInvoiceService::class)->previewText(collect([$invoice]));

        $this->assertStringNotContainsString('Entregar a:', $out);
    }

    public function test_includes_quality_and_darkening_commands(): void
    {
        $out = app(EscpInvoiceService::class)->build(collect([$this->invoiceWithLines()]));

        $this->assertStringContainsString("\x1B@", $out);      // init
        $this->assertStringContainsString("\x1Bx\x01", $out);  // LQ
        $this->assertStringContainsString("\x1BE", $out);      // emphasized
        $this->assertStringContainsString("\x1BG", $out);      // double-strike
    }

    public function test_hardened_build_forces_unit_independent_commands(): void
    {
        // El flujo endurecido debe forzar explícitamente los parámetros que
        // varían entre unidades LX-350, para que todas impriman idéntico.
        $out = app(EscpInvoiceService::class)->buildHardened(collect([$this->invoiceWithLines()]));

        $this->assertStringContainsString("\x1B@", $out);        // reset
        $this->assertStringContainsString("\x1BU\x01", $out);    // unidireccional
        $this->assertStringContainsString("\x1B \x00", $out);    // ESC SP 0: cero espaciado extra
        $this->assertStringContainsString("\x1BR\x00", $out);    // juego internacional fijo
        $this->assertStringContainsString("\x1Bt\x01", $out);    // tabla de caracteres fija
        $this->assertStringContainsString("\x1BQ", $out);        // margen derecho fijo
        // No rompe el contenido ni el ASCII puro.
        $this->assertStringContainsString('GRUPO JAREMAR', $out);
    }

    public function test_hardened_build_stays_pure_ascii(): void
    {
        $out = app(EscpInvoiceService::class)->buildHardened(
            collect([$this->invoiceWithLines(['client_name' => 'PULPERIA EL ÑANDU'])])
        );

        $maxOrd = 0;
        foreach (str_split($out) as $ch) {
            $maxOrd = max($maxOrd, ord($ch));
        }
        $this->assertLessThanOrEqual(0x7E, $maxOrd);
    }

    public function test_contains_invoice_data(): void
    {
        $out = app(EscpInvoiceService::class)->build(collect([$this->invoiceWithLines()]));

        $this->assertStringContainsString('GRUPO JAREMAR', $out);
        $this->assertStringContainsString('F77000001', $out);
        $this->assertStringContainsString('PULPERIA PRUEBA', $out);
        $this->assertStringContainsString('TOTAL:', $out);
    }

    /**
     * Regresión: línea MIXTA UN (bonificación "1 caja + 56 uds" de la factura
     * real 002-001-01-03871160). Con quantity_fractions normalizado (152), la
     * descomposición debe imprimir la caja en Cj y las sueltas en Und — antes
     * la caja desaparecía y salía solo "56".
     */
    public function test_mixed_unit_line_prints_embedded_box_and_loose_units(): void
    {
        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);
        $invoice = Invoice::factory()->for($manifest)->create(['invoice_number' => 'F-MIX-ESCP']);

        InvoiceLine::factory()->for($invoice)->create([
            'product_id' => '82800087',
            'product_description' => 'SOFRITO CRIOLLO 8X12X87GR',
            'unit_sale' => 'UN',
            'quantity_box' => 1,
            'quantity_fractions' => 152,      // normalizado: 1 × 96 + 56
            'quantity_min_sale' => 152,
            'quantity_decimal' => 1.583,
            'conversion_factor' => 96,
            'subtotal' => 0,
            'tax' => 0,
            'tax18' => 0,
            'total' => 0,
        ]);
        $invoice->load('lines');

        $preview = app(EscpInvoiceService::class)->previewText(collect([$invoice]));

        // Columna Cj = 1, Und = 56, luego el código de producto.
        $this->assertMatchesRegularExpression('/^1\s+56\s+82800087/m', $preview);
    }

    /**
     * Regresión: línea MIXTA vendida en CJ (detectada en prod, factura 380:
     * 12 cajas + 48 sueltas, factor 96 → fractions normalizado 1200). La rama
     * CJ mostraba solo quantity_box y las 48 sueltas desaparecían de la
     * impresión. Debe salir "12 | 48". Una CJ pura sigue saliendo "N | vacío"
     * (cubierto por test_cj_line_shows_boxes_without_loose_units).
     */
    public function test_mixed_cj_line_prints_boxes_and_embedded_loose_units(): void
    {
        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);
        $invoice = Invoice::factory()->for($manifest)->create(['invoice_number' => 'F-MIXCJ-ESCP']);

        InvoiceLine::factory()->for($invoice)->create([
            'product_id' => '82800087',
            'product_description' => 'SOFRITO CRIOLLO 8X12X87GR',
            'unit_sale' => 'CJ',
            'quantity_box' => 12,
            'quantity_fractions' => 1200,     // normalizado: 12 × 96 + 48
            'quantity_min_sale' => 1200,
            'quantity_decimal' => 12.5,
            'conversion_factor' => 96,
        ]);
        $invoice->load('lines');

        $preview = app(EscpInvoiceService::class)->previewText(collect([$invoice]));

        // Columna Cj = 12, Und = 48, luego el código de producto.
        $this->assertMatchesRegularExpression('/^12\s+48\s+82800087/m', $preview);
    }

    public function test_fixed_mode_uses_one_page_length_and_form_feed_per_invoice(): void
    {
        // Modo fixed (papel perforado, default): el largo de página se fija
        // UNA vez (en el preamble) y hay un form feed por factura.
        config(['escp.form_mode' => 'fixed']);

        $manifest = Manifest::factory()->create(['number' => '770002']);
        $invoices = Invoice::factory()->count(3)->for($manifest)->create();
        $invoices->each(fn (Invoice $i) => InvoiceLine::factory()->count(1)->for($i)->create());
        $invoices->load('lines');

        $out = app(EscpInvoiceService::class)->build($invoices);

        $this->assertSame(1, substr_count($out, "\x1BC")); // un solo ESC C
        $this->assertSame(3, substr_count($out, "\x0C"));  // un FF por factura
    }

    public function test_dynamic_mode_page_length_matches_invoice_line_count(): void
    {
        config(['escp.form_mode' => 'dynamic']);

        $short = app(EscpInvoiceService::class)->build(collect([$this->invoiceWithLines([], 1)]));
        $long = app(EscpInvoiceService::class)->build(collect([$this->invoiceWithLines(['invoice_number' => 'F77000002'], 12)]));

        // El byte que sigue a "ESC C" es el largo de página en líneas.
        $shortLen = ord($short[strpos($short, "\x1BC") + 2]);
        $longLen = ord($long[strpos($long, "\x1BC") + 2]);

        $this->assertGreaterThan($shortLen, $longLen);
    }

    public function test_large_amounts_are_not_truncated(): void
    {
        // Regresión: con columnas de 8 caracteres, un monto como 121,050.00
        // (10 caracteres) se cortaba a "121,050." y desalineaba la fila.
        // Las columnas anchas deben mostrarlo completo.
        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);
        $invoice = Invoice::factory()->for($manifest)->create(['invoice_number' => 'F77000099']);

        InvoiceLine::factory()->for($invoice)->create([
            'price' => 807.00,
            'subtotal' => 121050.00,
            'tax' => 0,
            'tax18' => 0,
            'total' => 121050.00,
        ]);
        $invoice->load('lines');

        $out = app(EscpInvoiceService::class)->build(collect([$invoice]));

        $this->assertStringContainsString('121,050.00', $out);
    }

    public function test_long_product_description_is_not_truncated(): void
    {
        // Regresión: con la columna Descripcion fija en 20 chars, nombres como
        // "ORISOL BOLSC/V 700 mL MAYOREO1/20" (33 chars) se "comían" en la
        // impresión matriz. Ahora la descripción absorbe el ancho de línea (cpl)
        // y debe salir completa.
        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);
        $invoice = Invoice::factory()->for($manifest)->create(['invoice_number' => 'F77000100']);

        InvoiceLine::factory()->for($invoice)->create([
            'product_description' => 'ORISOL BOLSC/V 700 mL MAYOREO1/20',
        ]);
        $invoice->load('lines');

        $out = app(EscpInvoiceService::class)->build(collect([$invoice]));

        $this->assertStringContainsString('ORISOL BOLSC/V 700 mL MAYOREO1/20', $out);
    }

    /**
     * Regresión (factura real 002-001-01-03908303): una línea de 100 cajas de
     * HARINA GOLD STAR se imprimía "10" en la matriz porque la columna Cj medía
     * 2 caracteres y col() truncaba. No era un desajuste visual: era una
     * CANTIDAD EQUIVOCADA en el documento que firma el cliente. El PDF/HTML sí
     * mostraba 100, así que solo se veía en el papel.
     */
    public function test_three_digit_box_quantity_is_not_truncated(): void
    {
        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);
        $invoice = Invoice::factory()->for($manifest)->create(['invoice_number' => 'F77000101']);

        InvoiceLine::factory()->for($invoice)->create([
            'product_id' => '40300012',
            'product_description' => 'HARINA GOLD STAR CLASI400GX25U',
            'unit_sale' => 'CJ',
            'quantity_box' => 100,
            'quantity_fractions' => 2500,     // 100 × 25, CJ pura → sueltas 0
            'quantity_min_sale' => 2500,
            'quantity_decimal' => 100,
            'conversion_factor' => 25,
            'price' => 245.00,
            'subtotal' => 23275.00,
            'tax' => 0,
            'tax18' => 0,
            'total' => 23275.00,
        ]);
        $invoice->load('lines');

        $preview = app(EscpInvoiceService::class)->previewText(collect([$invoice]));

        // Cj = 100 (los tres dígitos), Und vacía, luego código y descripción
        // completa: la descripción de 30 chars sigue cabiendo en la columna.
        $this->assertMatchesRegularExpression(
            '/^100\s+40300012\s+HARINA GOLD STAR CLASI400GX25U/m',
            $preview
        );
    }

    /**
     * Blindaje del mismo defecto para cualquier cantidad: ni Cj ni Und se
     * truncan aunque excedan su columna de 3 (4 dígitos). El excedente lo
     * absorbe Descripcion, así que el ancho de línea NO crece y las columnas de
     * dinero no se desplazan ni se recortan.
     *
     * OJO con los límites del esquema: quantity_fractions es decimal(10,4), o
     * sea < 10^6, y fractions = cajas × factor + sueltas. Por eso NO se pueden
     * tener 4 dígitos en Cj y en Und en la MISMA línea (exigiría fractions ≥
     * 1,001,000 → overflow de Postgres). Se cubre una línea por caso.
     */
    public function test_four_digit_quantities_never_truncate_nor_widen_the_line(): void
    {
        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);
        $invoice = Invoice::factory()->for($manifest)->create(['invoice_number' => 'F77000102']);

        // Cj de 4 dígitos: 1200 cajas de factor 120 + 119 sueltas = 144,119.
        InvoiceLine::factory()->for($invoice)->create([
            'product_id' => '40300012',
            'product_description' => 'HARINA GOLD STAR',
            'unit_sale' => 'CJ',
            'quantity_box' => 1200,
            'quantity_fractions' => 144119,
            'quantity_min_sale' => 144119,
            'quantity_decimal' => 1200.99,
            'conversion_factor' => 120,
            'price' => 807.00,
            'subtotal' => 121050.00,
            'tax' => 0,
            'tax18' => 0,
            'total' => 121050.00,
        ]);

        // Und de 4 dígitos: 900 cajas de factor 1001 + 1000 sueltas = 901,900.
        InvoiceLine::factory()->for($invoice)->create([
            'product_id' => '40300018',
            'product_description' => 'HARINA G.S.BALEADA',
            'unit_sale' => 'CJ',
            'quantity_box' => 900,
            'quantity_fractions' => 901900,
            'quantity_min_sale' => 901900,
            'quantity_decimal' => 900.99,
            'conversion_factor' => 1001,
            'price' => 265.00,
            'subtotal' => 1258.75,
            'tax' => 0,
            'tax18' => 0,
            'total' => 1258.75,
        ]);
        $invoice->load('lines');

        $preview = app(EscpInvoiceService::class)->previewText(collect([$invoice]));

        // Cantidades íntegras y sin separador de miles ("1200", no "1,200").
        $this->assertMatchesRegularExpression('/^1200\s+119\s+40300012/m', $preview);
        $this->assertMatchesRegularExpression('/^900\s+1000\s+40300018/m', $preview);
        // El monto de la fila con Cj desbordada tampoco se recorta.
        $this->assertStringContainsString('121,050.00', $preview);

        // Ninguna línea excede el ancho útil: el excedente salió de Descripcion.
        $cpl = max(40, (int) config('escp.chars_per_line', 92));
        foreach (explode("\n", $preview) as $line) {
            $this->assertLessThanOrEqual($cpl, mb_strlen($line), 'Linea mas ancha que cpl: '.$line);
        }
    }

    /**
     * Regresion: la linea "Cliente:" se armaba concatenando sin pasar por el
     * ancho util, asi que una razon social larga la empujaba mas alla del
     * margen derecho (95 chars contra 92). Con el margen derecho fijado por
     * software en buildHardened (ESC Q), la impresora envuelve el sobrante y
     * el "Ruta:" -- el dato que lee el motorista -- se cae a la linea
     * siguiente. Debe PARTIRSE por palabras, sin perder ningun dato.
     */
    public function test_long_client_name_wraps_without_losing_route_or_rtn(): void
    {
        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);
        $invoice = Invoice::factory()->for($manifest)->create([
            'invoice_number' => 'F77000103',
            'client_id' => 'CLI1411',
            'client_name' => 'DISTRIBUIDORA Y COMERCIALIZADORA DE PRODUCTOS ALIMENTICIOS DEL OCCIDENTE',
            'client_rtn' => '42851109784665',
            'payment_type' => 'CONTADO',
            'route_number' => '25',
        ]);
        InvoiceLine::factory()->for($invoice)->create();
        $invoice->load('lines');

        $preview = app(EscpInvoiceService::class)->previewText(collect([$invoice]));

        $cpl = max(40, (int) config('escp.chars_per_line', 92));
        foreach (explode("\n", $preview) as $line) {
            $this->assertLessThanOrEqual($cpl, mb_strlen($line), 'Linea mas ancha que cpl: '.$line);
        }

        // Nada se pierde al partir: RTN, forma de pago y ruta siguen ahi.
        $this->assertStringContainsString('42851109784665', $preview);
        $this->assertStringContainsString('Pago: CONTADO', $preview);
        $this->assertStringContainsString('Ruta: 25', $preview);
    }

    public function test_long_invoice_paginates_without_repeating_full_header(): void
    {
        // Una factura de muchos productos se parte en varias formas, pero el
        // encabezado COMPLETO del emisor va SOLO en la primera; las siguientes
        // son continuación (línea de referencia + "Pagina X de Y"). Totales/
        // firmas solo en la última. NO hay reimpresión del encabezado.
        config(['escp.form_mode' => 'fixed', 'escp.page_length_lines' => 44]);

        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);
        $invoice = Invoice::factory()->for($manifest)->create(['invoice_number' => 'F77000200']);
        InvoiceLine::factory()->count(40)->for($invoice)->create();
        $invoice->load('lines');

        $out = app(EscpInvoiceService::class)->build(collect([$invoice]));

        // Al menos 2 formas → al menos 2 form feeds.
        $this->assertGreaterThanOrEqual(2, substr_count($out, "\x0C"));
        // El encabezado del emisor aparece UNA sola vez (no se reimprime).
        $this->assertSame(1, substr_count($out, 'GRUPO JAREMAR'));
        // Las formas siguientes son continuación.
        $this->assertStringContainsString('(Continuacion)', $out);
        $this->assertStringContainsString('Pagina 1 de', $out);
        $this->assertStringContainsString('Pagina 2 de', $out);
        // Los totales aparecen UNA sola vez (solo en la última forma).
        $this->assertSame(1, substr_count($out, 'TOTAL:'));
    }

    public function test_short_invoice_stays_single_form_without_page_indicator(): void
    {
        // Regresión: una factura corta debe seguir en UNA sola forma, sin
        // indicador de página (salida como la histórica).
        config(['escp.form_mode' => 'fixed', 'escp.page_length_lines' => 44]);

        $out = app(EscpInvoiceService::class)->build(collect([$this->invoiceWithLines([], 3)]));

        $this->assertSame(1, substr_count($out, "\x0C"));       // un solo form feed
        $this->assertStringNotContainsString('Pagina 1 de', $out);
        $this->assertSame(1, substr_count($out, 'GRUPO JAREMAR'));
    }

    public function test_un_line_shows_box_equivalence(): void
    {
        // Una línea vendida en UN se muestra en cajas equivalentes + sueltas,
        // igual que la Sublista: 64 unidades con factor 60 → 1 caja y 4 unidades.
        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);
        $invoice = Invoice::factory()->for($manifest)->create(['invoice_number' => 'F77000300']);
        InvoiceLine::factory()->for($invoice)->create([
            'product_id' => 'ZZ99',
            'unit_sale' => 'UN',
            'quantity_fractions' => 64,
            'quantity_box' => 0,
            'conversion_factor' => 60,
        ]);
        $invoice->load('lines');

        $out = app(EscpInvoiceService::class)->build(collect([$invoice]));

        // La fila de ZZ99 muestra Cj=1 y Und=4 (64 ÷ 60), sin la fracción cruda 64.
        $this->assertMatchesRegularExpression('/1\s+4\s+ZZ99/', $out);
    }

    public function test_cj_line_shows_boxes_without_loose_units(): void
    {
        // Una línea vendida en CJ muestra las cajas reales y 0 sueltas, aunque
        // quantity_fractions traiga el total (cajas × factor).
        $manifest = Manifest::factory()->create(['number' => (string) (++static::$manifestSeq)]);
        $invoice = Invoice::factory()->for($manifest)->create(['invoice_number' => 'F77000301']);
        InvoiceLine::factory()->for($invoice)->create([
            'product_id' => 'CJ01',
            'unit_sale' => 'CJ',
            'quantity_box' => 2,
            'quantity_fractions' => 50,   // 2 cajas × 25
            'conversion_factor' => 25,
        ]);
        $invoice->load('lines');

        $out = app(EscpInvoiceService::class)->build(collect([$invoice]));

        // La fila de CJ01 muestra Cj=2 y Und en blanco (0 sueltas no se imprime):
        // entre el "2" y el código solo hay espacios, ningún otro dígito.
        $this->assertMatchesRegularExpression('/2\s+CJ01/', $out);
        // No debe aparecer la fracción cruda redundante "50".
        $this->assertStringNotContainsString('2  50', $out);
    }

    public function test_output_is_pure_ascii(): void
    {
        $out = app(EscpInvoiceService::class)->build(
            collect([$this->invoiceWithLines(['client_name' => 'PULPERIA EL ÑANDU'])])
        );

        $maxOrd = 0;
        foreach (str_split($out) as $ch) {
            $maxOrd = max($maxOrd, ord($ch));
        }
        $this->assertLessThanOrEqual(0x7E, $maxOrd);
    }

    public function test_preview_text_has_no_control_codes(): void
    {
        $preview = app(EscpInvoiceService::class)->previewText(collect([$this->invoiceWithLines()]));

        $this->assertStringContainsString('GRUPO JAREMAR', $preview);
        $this->assertStringContainsString('F77000001', $preview);
        $this->assertStringNotContainsString("\x1B", $preview);
        $this->assertStringNotContainsString("\x0C", $preview);
    }
}
