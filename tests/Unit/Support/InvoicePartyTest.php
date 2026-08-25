<?php

namespace Tests\Unit\Support;

use App\Models\Invoice;
use App\Support\InvoiceParty;
use PHPUnit\Framework\TestCase;

/**
 * Contrato de los nombres de las partes de la factura para impresión.
 *
 * El caso que originó esto: la factura 002-001-01-03931792 salía por la matriz
 * como `Cliente: 98074115-"CONSUMIDOR FINAL"` y el motorista no tenía forma de
 * saber que iba a POIT 24/7 — el dato SÍ estaba en la BD, nunca se imprimía.
 */
class InvoicePartyTest extends TestCase
{
    private function invoice(?string $clientName, ?string $deliverTo): Invoice
    {
        $invoice = new Invoice;
        $invoice->client_name = $clientName;
        $invoice->deliver_to = $deliverTo;

        return $invoice;
    }

    public function test_strips_the_literal_quotes_jaremar_sends_in_client_name(): void
    {
        $invoice = $this->invoice('"CONSUMIDOR FINAL"', 'POIT 24/7');

        $this->assertSame('CONSUMIDOR FINAL', InvoiceParty::clientName($invoice));
    }

    public function test_deliver_to_is_returned_when_it_names_a_different_business(): void
    {
        $invoice = $this->invoice('"CONSUMIDOR FINAL"', 'POIT 24/7');

        $this->assertSame('POIT 24/7', InvoiceParty::deliverTo($invoice));
    }

    public function test_deliver_to_is_empty_when_it_only_repeats_the_client(): void
    {
        // 9,514 de las 9,617 facturas de prod caen aquí: Jaremar manda el mismo
        // nombre en Cliente y EntregarA. No se gasta una línea de la forma.
        $invoice = $this->invoice('PULPERIA JARED', 'PULPERIA JARED');

        $this->assertSame('', InvoiceParty::deliverTo($invoice));
    }

    public function test_repetition_is_detected_across_quotes_case_and_spacing(): void
    {
        $invoice = $this->invoice('"PULPERIA  JARED"', 'Pulperia Jared');

        $this->assertSame('', InvoiceParty::deliverTo($invoice));
    }

    public function test_deliver_to_is_empty_when_jaremar_sends_nothing(): void
    {
        $this->assertSame('', InvoiceParty::deliverTo($this->invoice('PULPERIA JARED', null)));
        $this->assertSame('', InvoiceParty::deliverTo($this->invoice('PULPERIA JARED', '   ')));
        $this->assertSame('', InvoiceParty::deliverTo($this->invoice('PULPERIA JARED', '""')));
    }

    public function test_apostrophes_inside_a_business_name_survive(): void
    {
        // El recorte es solo de comillas dobles: un nombre como D'ANGELO no se
        // puede mutilar, es lo que el motorista lee para entregar.
        $invoice = $this->invoice('"CONSUMIDOR FINAL"', "PULPERIA D'ANGELO");

        $this->assertSame("PULPERIA D'ANGELO", InvoiceParty::deliverTo($invoice));
    }
}
