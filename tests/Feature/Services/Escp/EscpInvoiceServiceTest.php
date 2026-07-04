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
