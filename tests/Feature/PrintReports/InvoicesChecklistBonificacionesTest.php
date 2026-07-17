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
 * Sublista de Facturas / checklist (GET /imprimir/reportes/facturas-checklist)
 * — columna "Bonif.".
 *
 * Complemento del caso frijol 200gr (2026-07-16): la Sublista de Productos
 * dice QUÉ productos vinieron bonificados; el checklist marca EN CUÁLES
 * facturas van, para que el operador lo verifique al entregar.
 *
 * Contrato protegido:
 *   1. Factura con línea total=0 y cantidad>0 → badge "★ BONIF." en su fila
 *      + leyenda con el conteo. Facturas normales quedan sin marcar.
 *   2. Sin bonificaciones en el manifiesto → ni badge ni leyenda.
 *   3. Línea total=0 con cantidad 0 (marcador de descuento) → NO marca.
 */
class InvoicesChecklistBonificacionesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $invoicesLines
     *         Lista de facturas; cada una es una lista de attrs de líneas.
     */
    private function makeManifest(array $invoicesLines): Manifest
    {
        $warehouse = Warehouse::where('code', 'OAC')->first()
            ?? Warehouse::factory()->create(['code' => 'OAC', 'name' => 'OAC']);

        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);

        foreach ($invoicesLines as $lines) {
            $invoice = Invoice::factory()->create([
                'manifest_id' => $manifest->id,
                'warehouse_id' => $warehouse->id,
            ]);

            foreach ($lines as $attrs) {
                InvoiceLine::factory()->for($invoice, 'invoice')->create($attrs);
            }
        }

        return $manifest;
    }

    private function renderChecklist(Manifest $manifest)
    {
        $payload = Crypt::encryptString(json_encode(['manifest_id' => $manifest->id]));

        return $this->actingAs(User::factory()->create())
            ->get('/imprimir/reportes/facturas-checklist?payload='.urlencode($payload));
    }

    public function test_invoice_with_bonus_line_is_marked_and_legend_shows_count(): void
    {
        $normalLine = [
            'product_id' => '86800016',
            'product_description' => 'FRIJOLES 24X360G',
            'unit_sale' => 'CJ',
            'quantity_box' => 1,
            'quantity_fractions' => 24,
            'conversion_factor' => 24,
            'total' => 620.00,
        ];
        $bonusLine = [
            'product_id' => '86800002',
            'product_description' => 'FRIJOLES 24X200GR',
            'unit_sale' => 'CJ',
            'quantity_box' => 1,
            'quantity_fractions' => 24,
            'conversion_factor' => 24,
            'total' => 0,
        ];

        // Factura 1: normal + bonificación. Factura 2: solo normal.
        $manifest = $this->makeManifest([
            [$normalLine, $bonusLine],
            [$normalLine],
        ]);

        $response = $this->renderChecklist($manifest);
        $response->assertOk();
        $content = $response->getContent();

        // Badge en UNA fila + badge de la leyenda = 2 apariciones exactas.
        $this->assertSame(2, substr_count($content, 'class="bonif-badge"'));
        // Leyenda declara el conteo sobre el total de facturas.
        $response->assertSee('1 de 2 facturas llevan bonificaci&oacute;n', false);
    }

    public function test_manifest_without_bonus_has_no_badge_nor_legend(): void
    {
        $manifest = $this->makeManifest([
            [[
                'product_id' => '86800016',
                'product_description' => 'FRIJOLES 24X360G',
                'unit_sale' => 'CJ',
                'quantity_box' => 1,
                'quantity_fractions' => 24,
                'conversion_factor' => 24,
                'total' => 620.00,
            ]],
        ]);

        $response = $this->renderChecklist($manifest);

        $response->assertOk();
        $this->assertSame(0, substr_count($response->getContent(), 'class="bonif-badge"'));
        $response->assertDontSee('llevan bonificaci&oacute;n', false);
    }

    public function test_zero_quantity_zero_total_line_does_not_mark_invoice(): void
    {
        $manifest = $this->makeManifest([
            [
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
            ],
        ]);

        $response = $this->renderChecklist($manifest);

        $response->assertOk();
        $this->assertSame(0, substr_count($response->getContent(), 'class="bonif-badge"'));
    }
}
