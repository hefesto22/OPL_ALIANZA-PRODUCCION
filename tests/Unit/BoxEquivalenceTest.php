<?php

namespace Tests\Unit;

use App\Support\BoxEquivalence;
use PHPUnit\Framework\TestCase;

/**
 * Descomposición unidades → cajas + sueltas para la Sublista de Productos.
 */
class BoxEquivalenceTest extends TestCase
{
    public function test_splits_units_into_boxes_and_loose(): void
    {
        // 258 unidades con factor 96 → 2 cajas + 66 sueltas.
        $this->assertSame(['cajas' => 2, 'sueltas' => 66], BoxEquivalence::split(258, 96));
    }

    public function test_exact_multiple_has_no_loose(): void
    {
        $this->assertSame(['cajas' => 2, 'sueltas' => 0], BoxEquivalence::split(192, 96));
    }

    public function test_less_than_one_box_stays_all_loose(): void
    {
        $this->assertSame(['cajas' => 0, 'sueltas' => 50], BoxEquivalence::split(50, 96));
    }

    public function test_factor_one_or_zero_keeps_everything_loose(): void
    {
        // Sin factor válido no hay caja que calcular.
        $this->assertSame(['cajas' => 0, 'sueltas' => 30], BoxEquivalence::split(30, 1));
        $this->assertSame(['cajas' => 0, 'sueltas' => 30], BoxEquivalence::split(30, 0));
    }

    public function test_negative_units_clamped_to_zero(): void
    {
        $this->assertSame(['cajas' => 0, 'sueltas' => 0], BoxEquivalence::split(-5, 96));
    }
}
