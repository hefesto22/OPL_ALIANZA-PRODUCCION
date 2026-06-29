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

    public function test_contains_invoice_data(): void
    {
        $out = app(EscpInvoiceService::class)->build(collect([$this->invoiceWithLines()]));

        $this->assertStringContainsString('GRUPO JAREMAR', $out);
        $this->assertStringContainsString('F77000001', $out);
        $this->assertStringContainsString('PULPERIA PRUEBA', $out);
        $this->assertStringContainsString('TOTAL:', $out);
    }

    public function test_sets_dynamic_page_length_and_form_feed_per_invoice(): void
    {
        $manifest = Manifest::factory()->create(['number' => '770002']);
        $invoices = Invoice::factory()->count(3)->for($manifest)->create();
        $invoices->each(fn (Invoice $i) => InvoiceLine::factory()->count(1)->for($i)->create());
        $invoices->load('lines');

        $out = app(EscpInvoiceService::class)->build($invoices);

        // Un ESC C (set page length) y un form feed por cada factura.
        $this->assertSame(3, substr_count($out, "\x1BC"));
        $this->assertSame(3, substr_count($out, "\x0C"));
    }

    public function test_page_length_matches_invoice_line_count(): void
    {
        // Una factura con más líneas debe declarar un largo de página mayor.
        $short = app(EscpInvoiceService::class)->build(collect([$this->invoiceWithLines([], 1)]));
        $long = app(EscpInvoiceService::class)->build(collect([$this->invoiceWithLines(['invoice_number' => 'F77000002'], 12)]));

        // El byte que sigue a "ESC C" es el largo de página en líneas.
        $shortLen = ord($short[strpos($short, "\x1BC") + 2]);
        $longLen = ord($long[strpos($long, "\x1BC") + 2]);

        $this->assertGreaterThan($shortLen, $longLen);
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
