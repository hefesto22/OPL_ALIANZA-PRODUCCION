<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 *
 * Default: una línea con 10 cajas × factor 12 = 120 fracciones (unidades
 * vendibles), con precio unitario Q10 → total de línea Q1,200.
 *
 * Estos números están elegidos para ser fáciles de razonar en tests:
 * devolver 1 caja = 12 unidades = Q120, devolver la línea completa = Q1,200.
 *
 * Los tests que necesiten montos específicos deben usar state() para
 * sobrescribir quantity_box, conversion_factor y price_min_sale.
 */
class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    public function definition(): array
    {
        $boxes            = 10;
        $conversionFactor = 12;
        $pricePerUnit     = 10.0;
        $fractions        = $boxes * $conversionFactor;    // 120
        $lineTotal        = $fractions * $pricePerUnit;    // 1200

        return [
            'invoice_id'          => Invoice::factory(),
            'jaremar_line_id'     => fake()->numerify('L####'),
            'invoice_jaremar_id'  => fake()->numberBetween(1, 99999),
            'line_number'         => fake()->numberBetween(1, 50),
            'product_id'          => fake()->bothify('PRD###'),
            'product_description' => fake()->words(3, true),
            'product_type'        => 'A',
            'unit_sale'           => 'UN',
            'quantity_fractions'  => $fractions,
            'quantity_decimal'    => $boxes,
            'quantity_box'        => $boxes,
            'quantity_min_sale'   => $fractions,
            'conversion_factor'   => $conversionFactor,
            'cost'                => $pricePerUnit * 0.7,
            'price'               => $pricePerUnit * $conversionFactor, // precio por caja
            'price_min_sale'      => $pricePerUnit,                      // precio por unidad
            'subtotal'            => $lineTotal,
            'discount'            => 0,
            'discount_percent'    => 0,
            'tax'                 => 0,
            'tax_percent'         => 0,
            'tax18'               => 0,
            'total'               => $lineTotal,
            'weight'              => 0,
            'volume'              => 0,
        ];
    }

    /**
     * Construye una línea con parámetros sencillos: cajas y precio unitario.
     * Recalcula quantity_fractions y total automáticamente para mantener
     * la coherencia interna de la fila (evita tests con datos inconsistentes).
     */
    public function withQuantity(int $boxes, float $pricePerUnit, int $conversionFactor = 12): static
    {
        $fractions = $boxes * $conversionFactor;
        $lineTotal = $fractions * $pricePerUnit;

        return $this->state(fn () => [
            'quantity_box'        => $boxes,
            'quantity_fractions'  => $fractions,
            'quantity_min_sale'   => $fractions,
            'quantity_decimal'    => $boxes,
            'conversion_factor'   => $conversionFactor,
            'price_min_sale'      => $pricePerUnit,
            'price'               => $pricePerUnit * $conversionFactor,
            'subtotal'            => $lineTotal,
            'total'               => $lineTotal,
        ]);
    }
}
