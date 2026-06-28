<?php

namespace Tests\Feature\Services\Escp;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Manifest;
use App\Services\Escp\EscpInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests del flujo ESC/P para impresión en matriz de punto (Epson LX-350).
 *
 * Verifican: comandos de calidad (LQ) + condensada + oscurecido (emphasized
 * + double-strike), que los datos están, ASCII puro (sin acentos
 * garabateados), salto de página por factura, y que el preview en pantalla
 * coincide con lo impreso (WYSIWYG).
 */
class EscpInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceWithLines(array $overrides = [], int $lines = 2): Invoice
    {
        $manifest = Manifest::factory()->create(['number' => '770001']);

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

    public function test_escp_includes_quality_and_darkening_commands(): void
    {
        $out = app(EscpInvoiceService::class)->build(collect([$this->invoiceWithLines()]));

        $this->assertStringContainsString("\x1B@", $out);      // init
        $this->assertStringContainsString("\x1Bx\x01", $out);  // LQ
        $this->assertStringContainsString("\x0F", $out);       // condensada
        $this->assertStringContainsString("\x1BE", $out);      // emphasized
        $this->assertStringContainsString("\x1BG", $out);      // double-strike
        $this->assertStringContainsString("\x0C", $out);       // form feed
    }

    public function test_escp_contains_invoice_data(): void
    {
        $out = app(EscpInvoiceService::class)->build(collect([$this->invoiceWithLines()]));

        $this->assertStringContainsString('F77000001', $out);
        $this->assertStringContainsString('PULPERIA PRUEBA', $out);
        $this->assertStringContainsString('TOTAL A PAGAR', $out);
    }

    public function test_escp_is_transliterated_to_pure_ascii(): void
    {
        $out = app(EscpInvoiceService::class)->build(
            collect([$this->invoiceWithLines(['client_name' => 'PULPERIA EL ÑANDU ARBOL'])])
        );

        $this->assertStringContainsString('PULPERIA EL', $out);

        $maxOrd = 0;
        foreach (str_split($out) as $ch) {
            $maxOrd = max($maxOrd, ord($ch));
        }
        $this->assertLessThanOrEqual(0x7E, $maxOrd);
    }

    public function test_each_invoice_gets_its_own_form_feed(): void
    {
        $manifest = Manifest::factory()->create(['number' => '770002']);
        $invoices = Invoice::factory()->count(3)->for($manifest)->create();
        $invoices->each(fn (Invoice $i) => InvoiceLine::factory()->count(1)->for($i)->create());
        $invoices->load('lines');

        $out = app(EscpInvoiceService::class)->build($invoices);

        $this->assertSame(3, substr_count($out, "\x0C"));
    }

    public function test_preview_text_matches_printed_content_without_control_codes(): void
    {
        $invoice = $this->invoiceWithLines();
        $preview = app(EscpInvoiceService::class)->previewText(collect([$invoice]));

        // El preview tiene el mismo contenido legible…
        $this->assertStringContainsString('F77000001', $preview);
        $this->assertStringContainsString('TOTAL A PAGAR', $preview);

        // …pero SIN bytes de control ESC/P (es texto para pantalla).
        $this->assertStringNotContainsString("\x1B", $preview);
        $this->assertStringNotContainsString("\x0C", $preview);
    }
}
