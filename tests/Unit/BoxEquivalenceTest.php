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

    // ── totalFractions: normalización de líneas de Jaremar ──────────────

    public function test_total_fractions_adds_embedded_boxes_of_mixed_line(): void
    {
        // Línea MIXTA real (factura 002-001-01-03871160): 1 caja + 56 sueltas,
        // factor 96 → 56 < 96, la caja NO puede estar incluida → 152.
        $this->assertSame(152.0, BoxEquivalence::totalFractions(56.0, 1.0, 96));
    }

    public function test_total_fractions_normalizes_pure_box_line(): void
    {
        // Caso CJ puro (fracciones = 0): 12 cajas × 24 = 288.
        $this->assertSame(288.0, BoxEquivalence::totalFractions(0.0, 12.0, 24));
    }

    public function test_total_fractions_keeps_pure_unit_line_untouched(): void
    {
        // UN puro (sin cajas): las fracciones ya son el total.
        $this->assertSame(30.0, BoxEquivalence::totalFractions(30.0, 0.0, 96));
    }

    public function test_total_fractions_is_idempotent_on_normalized_line(): void
    {
        // Ya normalizada (152 >= 1 × 96): re-aplicar NO duplica la caja.
        $this->assertSame(152.0, BoxEquivalence::totalFractions(152.0, 1.0, 96));
        // CJ ya normalizada (fracciones = cajas × factor exacto): sin cambio.
        $this->assertSame(288.0, BoxEquivalence::totalFractions(288.0, 12.0, 24));
    }

    public function test_total_fractions_with_invalid_factor_treats_factor_as_one(): void
    {
        // Factor 0/1: cada caja aporta 1 fracción como mínimo.
        $this->assertSame(3.0, BoxEquivalence::totalFractions(1.0, 2.0, 0));
        $this->assertSame(3.0, BoxEquivalence::totalFractions(1.0, 2.0, 1));
    }
}
