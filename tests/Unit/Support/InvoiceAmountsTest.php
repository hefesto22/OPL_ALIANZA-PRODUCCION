<?php

namespace Tests\Unit\Support;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\InvoiceAmounts;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Desglose del pie de factura del Formato Hosana: Importe Gravado 15 %,
 * Gravado 18 %, Exento, SubTotal, Impuesto 15 %, Impuesto 18 % y TOTAL — todo a
 * partir de lo que manda Jaremar. Exonerado y Descuento no se imprimen.
 */
class InvoiceAmountsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $lines
     */
    private function invoice(array $attributes, array $lines = []): Invoice
    {
        $invoice = (new Invoice)->forceFill(array_merge([
            'importe_excento' => 0,
            'importe_exonerado' => 0,
            'importe_gravado' => 0,
            'isv15' => 0,
            'isv18' => 0,
            'total' => 0,
        ], $attributes));

        $invoice->setRelation('lines', new Collection(array_map(
            fn (array $line): InvoiceLine => (new InvoiceLine)->forceFill(array_merge([
                'product_type' => 'A',
                'subtotal' => 0,
                'tax' => 0,
                'tax18' => 0,
                'total' => 0,
            ], $line)),
            $lines
        )));

        return $invoice;
    }

    public function test_factura_normal_todo_el_gravado_va_al_15(): void
    {
        $amounts = InvoiceAmounts::breakdown($this->invoice([
            'importe_gravado' => 878.29,
            'importe_excento' => 120.50,
            'isv15' => 131.75,
            'total' => 1130.54,
        ]));

        $this->assertSame([
            'gravado15' => 878.29,
            'gravado18' => 0.0,
            'exento' => 120.5,
            'subtotal' => 998.79,
            'isv15' => 131.75,
            'isv18' => 0.0,
            'total' => 1130.54,
        ], $amounts);
    }

    public function test_no_trae_exonerado_ni_descuento(): void
    {
        $amounts = InvoiceAmounts::breakdown($this->invoice(['importe_gravado' => 100.00]));

        $this->assertArrayNotHasKey('exonerado', $amounts);
        $this->assertArrayNotHasKey('descuento', $amounts);
    }

    public function test_el_subtotal_sigue_sumando_el_exonerado_aunque_no_se_imprima(): void
    {
        // Igual que siempre: SubTotal = Exento + Exonerado + Gravado.
        $amounts = InvoiceAmounts::breakdown($this->invoice([
            'importe_exonerado' => 250.00,
            'importe_excento' => 75.25,
            'importe_gravado' => 100.00,
        ]));

        $this->assertSame(425.25, $amounts['subtotal']);
    }

    public function test_factura_solo_de_bonificacion_muestra_los_montos_de_jaremar(): void
    {
        // 002-001-01-04032897: la base va por fuera y el cliente solo paga el ISV.
        $amounts = InvoiceAmounts::breakdown($this->invoice([
            'importe_gravado' => 5298.09,
            'isv15' => 794.71,
            'total' => 794.71,
        ]));

        $this->assertSame(5298.09, $amounts['gravado15']);
        $this->assertSame(5298.09, $amounts['subtotal']);
        $this->assertSame(794.71, $amounts['isv15']);
        $this->assertSame(794.71, $amounts['total']);
    }

    public function test_si_solo_hay_isv_18_todo_el_gravado_va_al_18(): void
    {
        $amounts = InvoiceAmounts::breakdown($this->invoice([
            'importe_gravado' => 1000.00,
            'isv18' => 180.00,
            'total' => 1180.00,
        ]));

        $this->assertSame(0.0, $amounts['gravado15']);
        $this->assertSame(1000.0, $amounts['gravado18']);
    }

    public function test_con_isv_15_y_18_el_gravado_se_parte_por_las_lineas(): void
    {
        $amounts = InvoiceAmounts::breakdown($this->invoice([
            'importe_gravado' => 1500.00,
            'isv15' => 150.00,
            'isv18' => 90.00,
            'total' => 1740.00,
        ], [
            ['subtotal' => 1000.00, 'tax' => 150.00],
            ['subtotal' => 500.00, 'tax18' => 90.00],
        ]));

        $this->assertSame(1000.0, $amounts['gravado15']);
        $this->assertSame(500.0, $amounts['gravado18']);
    }

    public function test_con_isv_15_y_18_sin_lineas_la_base_18_sale_del_impuesto(): void
    {
        $amounts = InvoiceAmounts::breakdown($this->invoice([
            'importe_gravado' => 1500.00,
            'isv15' => 150.00,
            'isv18' => 90.00,
        ]));

        $this->assertSame(500.0, $amounts['gravado18']);
        $this->assertSame(1000.0, $amounts['gravado15']);
    }
}
