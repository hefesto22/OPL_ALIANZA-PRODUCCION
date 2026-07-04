<?php

namespace Tests\Feature\Console;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backfill de líneas MIXTAS históricas (cajas embebidas de Jaremar).
 *
 * El comando normaliza quantity_fractions al total real (cajas × factor +
 * sueltas) SOLO donde la caja no está incluida (fractions < box × factor).
 * Debe ser idempotente y no tocar líneas ya normalizadas.
 */
class NormalizeMixedInvoiceLinesTest extends TestCase
{
    use RefreshDatabase;

    /** Línea mixta CRUDA como quedó en producción antes del fix. */
    private function rawMixedLine(): InvoiceLine
    {
        return InvoiceLine::factory()->for(Invoice::factory())->create([
            'unit_sale' => 'UN',
            'quantity_box' => 1,
            'quantity_fractions' => 56,   // solo las sueltas — caja aparte
            'quantity_min_sale' => 152,
            'conversion_factor' => 96,
        ]);
    }

    public function test_normalizes_raw_mixed_lines(): void
    {
        $mixed = $this->rawMixedLine();

        $this->artisan('invoice-lines:normalize-mixed')
            ->expectsOutputToContain('Líneas mixtas sin normalizar: 1')
            ->expectsOutputToContain('Líneas normalizadas: 1')
            ->assertSuccessful();

        // 1 × 96 + 56 = 152
        $this->assertEquals(152.0, (float) $mixed->fresh()->quantity_fractions);
    }

    public function test_is_idempotent_on_second_run(): void
    {
        $mixed = $this->rawMixedLine();

        $this->artisan('invoice-lines:normalize-mixed')->assertSuccessful();
        $this->artisan('invoice-lines:normalize-mixed')
            ->expectsOutputToContain('Líneas mixtas sin normalizar: 0')
            ->assertSuccessful();

        // Sin doble suma: sigue en 152, no 248.
        $this->assertEquals(152.0, (float) $mixed->fresh()->quantity_fractions);
    }

    public function test_does_not_touch_already_normalized_lines(): void
    {
        // CJ normalizada por el importador: fractions = cajas × factor exacto.
        $boxLine = InvoiceLine::factory()->for(Invoice::factory())->create([
            'unit_sale' => 'CJ',
            'quantity_box' => 12,
            'quantity_fractions' => 288,
            'conversion_factor' => 24,
        ]);
        // UN pura: sin cajas.
        $unitLine = InvoiceLine::factory()->for(Invoice::factory())->create([
            'unit_sale' => 'UN',
            'quantity_box' => 0,
            'quantity_fractions' => 30,
            'conversion_factor' => 96,
        ]);

        $this->artisan('invoice-lines:normalize-mixed')
            ->expectsOutputToContain('Líneas mixtas sin normalizar: 0')
            ->assertSuccessful();

        $this->assertEquals(288.0, (float) $boxLine->fresh()->quantity_fractions);
        $this->assertEquals(30.0, (float) $unitLine->fresh()->quantity_fractions);
    }

    public function test_dry_run_counts_without_modifying(): void
    {
        $mixed = $this->rawMixedLine();

        $this->artisan('invoice-lines:normalize-mixed --dry-run')
            ->expectsOutputToContain('Líneas mixtas sin normalizar: 1')
            ->expectsOutputToContain('Dry-run: no se modificó nada.')
            ->assertSuccessful();

        $this->assertEquals(56.0, (float) $mixed->fresh()->quantity_fractions);
    }
}
