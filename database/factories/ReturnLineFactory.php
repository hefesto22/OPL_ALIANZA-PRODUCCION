<?php

namespace Database\Factories;

use App\Models\InvoiceLine;
use App\Models\InvoiceReturn;
use App\Models\ReturnLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnLine>
 *
 * Default: una línea de devolución con 1 caja × factor 12 = 12 unidades a
 * precio Q10 → total Q120. Los números están alineados con InvoiceLineFactory
 * (10 cajas × 12 = 120 unidades, Q1,200) para que devolver "una caja" del
 * default de InvoiceLineFactory cuadre matemáticamente sin ajustes.
 *
 * El formato Jaremar exige `quantity_box` (cajas enteras) y `quantity`
 * (unidades sueltas) como columnas separadas. La factory default modela
 * una devolución de caja entera (quantity_box = 1, quantity = 0); los tests
 * de devoluciones por unidad deben usar el state ->loose($units, $unitPrice).
 */
class ReturnLineFactory extends Factory
{
    protected $model = ReturnLine::class;

    public function definition(): array
    {
        $boxes = 1;
        $conversionFactor = 12;
        $pricePerUnit = 10.0;
        $lineTotal = $boxes * $conversionFactor * $pricePerUnit;   // 120.00

        return [
            'return_id' => InvoiceReturn::factory(),
            'invoice_line_id' => InvoiceLine::factory(),
            'line_number' => fake()->numberBetween(1, 50),
            'product_id' => fake()->bothify('PRD###'),
            'product_description' => fake()->words(3, true),
            'quantity_box' => $boxes,
            'quantity' => 0,
            'line_total' => $lineTotal,
        ];
    }

    /**
     * Devolución de unidades sueltas (no cajas enteras).
     * Útil para tests que validen el cálculo Jaremar de fracciones.
     */
    public function loose(int $units, float $pricePerUnit = 10.0): static
    {
        return $this->state(fn () => [
            'quantity_box' => 0,
            'quantity' => $units,
            'line_total' => round($units * $pricePerUnit, 2),
        ]);
    }

    /**
     * Devolución de cajas + unidades mixtas.
     * Refleja el escenario más común en bodega: cliente devuelve 2 cajas
     * completas y 5 unidades sueltas adicionales.
     */
    public function mixed(int $boxes, int $units, float $pricePerUnit = 10.0, int $conversionFactor = 12): static
    {
        $total = round(($boxes * $conversionFactor + $units) * $pricePerUnit, 2);

        return $this->state(fn () => [
            'quantity_box' => $boxes,
            'quantity' => $units,
            'line_total' => $total,
        ]);
    }
}
