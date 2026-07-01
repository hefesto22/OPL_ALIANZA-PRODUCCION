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
 * Sublista de Productos (GET /imprimir/reportes/productos): equivalencia en
 * cajas para líneas vendidas en unidades (UN).
 *
 * Pedido de operación: cuando un producto viene en unidades sueltas, el
 * bodeguero quiere ver cuántas CAJAS son esas unidades + el sobrante.
 * Ej.: 258 unidades con factor 96 → "2 cj + 66 u". Las filas en CJ siguen
 * mostrando su conteo real de cajas, sin equivalencia.
 */
class ProductsReportCajasEquivTest extends TestCase
{
    use RefreshDatabase;

    private function makeManifestWithLine(array $lineAttributes): Manifest
    {
        $warehouse = Warehouse::where('code', 'OAC')->first()
            ?? Warehouse::factory()->create(['code' => 'OAC', 'name' => 'OAC']);

        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);

        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
        ]);

        InvoiceLine::factory()->for($invoice, 'invoice')->create(array_merge([
            'product_id' => '80800013',
            'product_description' => 'PASTA NORMAL 8X12X87GR',
            'total' => 2303.98,
        ], $lineAttributes));

        return $manifest;
    }

    private function renderReport(Manifest $manifest)
    {
        $payload = Crypt::encryptString(json_encode(['manifest_id' => $manifest->id]));

        return $this->actingAs(User::factory()->create())
            ->get('/imprimir/reportes/productos?payload='.urlencode($payload));
    }

    public function test_un_row_shows_box_equivalent_and_loose_units(): void
    {
        // 258 unidades con factor 96 → 2 cajas + 66 unidades sueltas.
        $manifest = $this->makeManifestWithLine([
            'unit_sale' => 'UN',
            'quantity_box' => 0,
            'quantity_fractions' => 258,
            'conversion_factor' => 96,
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        $response->assertSee('2 cj + 66 u', false);
        // El total real de unidades sigue visible para el conteo.
        $response->assertSee('258', false);
    }

    public function test_un_row_with_exact_box_multiple_omits_loose_suffix(): void
    {
        // 192 = 2×96 exacto → "2 cj" sin "+ 0 u".
        $manifest = $this->makeManifestWithLine([
            'unit_sale' => 'UN',
            'quantity_box' => 0,
            'quantity_fractions' => 192,
            'conversion_factor' => 96,
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        $response->assertSee('2 cj', false);
        $response->assertDontSee('2 cj + 0 u', false);
    }

    public function test_un_row_below_one_box_shows_no_box_equivalence(): void
    {
        // 50 unidades < 96 → no hay caja completa; solo se muestra el total.
        $manifest = $this->makeManifestWithLine([
            'unit_sale' => 'UN',
            'quantity_box' => 0,
            'quantity_fractions' => 50,
            'conversion_factor' => 96,
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        $response->assertSee('50', false);
        $response->assertDontSee('cj +', false);
    }

    public function test_cj_row_keeps_real_box_count_without_equivalence(): void
    {
        // Fila vendida en CJ: muestra cajas reales, sin texto de equivalencia.
        $manifest = $this->makeManifestWithLine([
            'unit_sale' => 'CJ',
            'quantity_box' => 11,
            'quantity_fractions' => 11 * 96,
            'conversion_factor' => 96,
            'total' => 8939.48,
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        $response->assertDontSee('cj +', false);
    }
}
