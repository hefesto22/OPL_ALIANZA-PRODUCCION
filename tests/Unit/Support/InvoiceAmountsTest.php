<?php

namespace Tests\Unit\Support;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\InvoiceAmounts;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

/**
 * Desglose del pie de factura del Formato Hosana: Importe Exonerado, Gravado
 * 15 %, Gravado 18 %, Exento, SubTotal, Descuento, Impuesto 15 %, Impuesto 18 %
 * y TOTAL — todo a partir de lo que manda Jaremar.
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
                'price' => 0,
                'quantity_decimal' => 0,
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
        ], [
            ['subtotal' => 878.29, 'tax' => 131.75, 'total' => 1010.04],
            ['subtotal' => 120.50, 'total' => 120.50],
        ]));

        $this->assertSame([
            'exonerado' => 0.0,
            'gravado15' => 878.29,
            'gravado18' => 0.0,
            'exento' => 120.5,
            'subtotal' => 998.79,
            'descuento' => 0.0,
            'isv15' => 131.75,
            'isv18' => 0.0,
            'total' => 1130.54,
        ], $amounts);

        // La cuenta se lee de corrido: SubTotal − Descuento + ISV = TOTAL.
        $this->assertEqualsWithDelta(
            $amounts['total'],
            $amounts['subtotal'] - $amounts['descuento'] + $amounts['isv15'] + $amounts['isv18'],
            0.001
        );
    }

    public function test_factura_solo_de_bonificacion_descuenta_la_mercaderia_y_cobra_solo_el_isv(): void
    {
        // Factura 002-001-01-04032897 (manifiesto 820348): 7 cajas de PASTA
        // NORMAL bonificadas a L 756.87. Las líneas van en 0.00, la base va por
        // fuera (Importe Gravado) y el cliente solo paga el ISV.
        $amounts = InvoiceAmounts::breakdown($this->invoice([
            'importe_gravado' => 5298.09,
            'isv15' => 794.71,
            'total' => 794.71,
        ], [
            ['product_type' => 'B', 'price' => 756.87, 'quantity_decimal' => 7],
        ]));

        $this->assertSame(5298.09, $amounts['gravado15']);
        $this->assertSame(5298.09, $amounts['subtotal']);
        $this->assertSame(5298.09, $amounts['descuento']);
        $this->assertSame(794.71, $amounts['isv15']);
        $this->assertSame(794.71, $amounts['total']);
        $this->assertEqualsWithDelta(794.71, $amounts['subtotal'] - $amounts['descuento'] + $amounts['isv15'], 0.001);
    }

    public function test_el_descuento_trunca_al_centavo_como_jaremar(): void
    {
        // 3.333 × 10 = 33.33 exacto; 1.995 × 3 = 5.985 → 5.98 (trunca, no redondea).
        $amounts = InvoiceAmounts::breakdown($this->invoice([], [
            ['product_type' => 'B', 'price' => 3.333, 'quantity_decimal' => 10],
            ['product_type' => 'b', 'price' => 1.995, 'quantity_decimal' => 3],
            ['product_type' => 'A', 'price' => 99.99, 'quantity_decimal' => 5, 'subtotal' => 499.95],
        ]));

        $this->assertSame(39.31, $amounts['descuento']);
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
        $this->assertSame(1500.0, $amounts['gravado15'] + $amounts['gravado18']);
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

    public function test_exonerado_y_exento_salen_tal_cual_y_suman_al_subtotal(): void
    {
        $amounts = InvoiceAmounts::breakdown($this->invoice([
            'importe_exonerado' => 250.00,
            'importe_excento' => 75.25,
            'importe_gravado' => 100.00,
            'isv15' => 15.00,
            'total' => 440.25,
        ]));

        $this->assertSame(250.0, $amounts['exonerado']);
        $this->assertSame(75.25, $amounts['exento']);
        $this->assertSame(425.25, $amounts['subtotal']);
    }
}
