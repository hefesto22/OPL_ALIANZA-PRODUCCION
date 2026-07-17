<?php

namespace Tests\Feature\PrintReports;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Manifest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Sublista de Productos — sección de BONIFICACIONES.
 *
 * Contexto (2026-07-16): bodega reclamó que "la bonificación del frijol 200
 * no aparecía en el manifiesto" (factura 002-001-01-03887790). La mercadería
 * bonificada SÍ está incluida en las filas consolidadas (la query principal
 * no filtra por total), pero al venir con valor L 0.00 nadie podía
 * identificarla. La sección de bonificaciones la lista aparte, después del
 * TOTAL GENERAL, SOLO como información — sin alterar filas ni totales.
 *
 * Contrato protegido:
 *   1. Línea con total=0 y cantidad>0 → aparece en la sección de
 *      bonificaciones Y sigue sumando en las filas/totales de arriba.
 *   2. Sin líneas de bonificación → la sección NO se renderiza.
 *   3. Línea con total=0 y cantidad 0 (marcador de descuento, dato corrupto)
 *      → NO se lista como bonificación.
 *   4. Bonificación en línea mixta cruda (cajas>0 y fracciones>0 juntas)
 *      → no pierde las cajas (misma matemática que la query principal).
 */
class ProductsReportBonificacionesTest extends TestCase
{
    use RefreshDatabase;

    private function makeManifestWithLines(array $lines): Manifest
    {
        $warehouse = Warehouse::where('code', 'OAC')->first()
            ?? Warehouse::factory()->create(['code' => 'OAC', 'name' => 'OAC']);

        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);

        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
        ]);

        foreach ($lines as $attrs) {
            InvoiceLine::factory()->for($invoice, 'invoice')->create($attrs);
        }

        return $manifest;
    }

    private function renderReport(Manifest $manifest)
    {
        $payload = Crypt::encryptString(json_encode(['manifest_id' => $manifest->id]));

        return $this->actingAs(User::factory()->create())
            ->get('/imprimir/reportes/productos?payload='.urlencode($payload));
    }

    public function test_bonus_line_renders_in_bonus_section_and_still_counts_in_main_rows(): void
    {
        // Producto vendido normal + producto que vino 100% como bonificación.
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '86800016',
                'product_description' => 'FRIJOLES 24X360G',
                'unit_sale' => 'CJ',
                'quantity_box' => 1,
                'quantity_fractions' => 24,
                'conversion_factor' => 24,
                'total' => 620.00,
            ],
            [
                'product_id' => '86800002',
                'product_description' => 'FRIJOLES 24X200GR',
                'unit_sale' => 'CJ',
                'quantity_box' => 1,
                'quantity_fractions' => 24,
                'conversion_factor' => 24,
                'total' => 0, // bonificación
            ],
        ]);

        $response = $this->renderReport($manifest);
        $response->assertOk();
        $content = $response->getContent();

        // La sección existe y declara su naturaleza informativa.
        $response->assertSee('Bonificaciones incluidas en este manifiesto', false);
        $response->assertSee('Ya est&aacute;n sumadas en las filas y totales de arriba', false);

        // El producto bonificado aparece DOS veces: fila consolidada + bonificación.
        $this->assertSame(2, substr_count($content, '86800002'));
        // El producto normal aparece UNA sola vez (no se cuela en bonificaciones).
        $this->assertSame(1, substr_count($content, '86800016'));
    }

    public function test_report_without_bonus_lines_has_no_bonus_section(): void
    {
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '86800016',
                'product_description' => 'FRIJOLES 24X360G',
                'unit_sale' => 'CJ',
                'quantity_box' => 1,
                'quantity_fractions' => 24,
                'conversion_factor' => 24,
                'total' => 620.00,
            ],
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        $response->assertDontSee('Bonificaciones incluidas', false);
    }

    public function test_zero_quantity_zero_total_line_is_not_listed_as_bonus(): void
    {
        // Marcador de descuento o dato corrupto: total=0 SIN mercadería.
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '86800016',
                'product_description' => 'FRIJOLES 24X360G',
                'unit_sale' => 'CJ',
                'quantity_box' => 1,
                'quantity_fractions' => 24,
                'conversion_factor' => 24,
                'total' => 620.00,
            ],
            [
                'product_id' => '99999999',
                'product_description' => 'DESCUENTO GLOBAL',
                'unit_sale' => 'UN',
                'quantity_box' => 0,
                'quantity_fractions' => 0,
                'conversion_factor' => 1,
                'total' => 0,
            ],
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        $response->assertDontSee('Bonificaciones incluidas', false);
    }

    public function test_mixed_raw_bonus_line_does_not_lose_boxes(): void
    {
        // Bonificación en línea MIXTA cruda de Jaremar: 1 caja + 12 sueltas,
        // factor 24 → fractions (12) < cajas × factor (24) → total real 36
        // unidades → "1 caja y 12 sueltas" en la sección de bonificaciones.
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '86800002',
                'product_description' => 'FRIJOLES 24X200GR',
                'unit_sale' => 'CJ',
                'quantity_box' => 1,
                'quantity_fractions' => 12,
                'conversion_factor' => 24,
                'total' => 0, // bonificación mixta
            ],
        ]);

        $response = $this->renderReport($manifest);
        $response->assertOk();

        $response->assertSee('Bonificaciones incluidas en este manifiesto', false);

        // La fila de bonificación descompone 36 unidades (factor 24) en 1 y 12.
        // BoxEquivalence::split(36, 24) → ['cajas' => 1, 'sueltas' => 12].
        $content = $response->getContent();
        $bonusSection = substr($content, strpos($content, '<div class="bonus-section">'));
        $this->assertStringContainsString('<td class="c">1</td>', $bonusSection);
        $this->assertStringContainsString('<td class="c">12</td>', $bonusSection);
    }
}
