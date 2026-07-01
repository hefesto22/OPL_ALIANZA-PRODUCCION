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
 * Sublista de Productos (GET /imprimir/reportes/productos).
 *
 * La descomposición unidades→cajas+sueltas está probada a fondo (matemática) en
 * BoxEquivalenceTest. Aquí verificamos el CABLEADO: que el reporte renderice sin
 * error con la lógica nueva (query con MAX(conversion_factor), helper en la vista
 * y totales descompuestos) tanto para líneas UN como CJ.
 */
class ProductsReportCajasEquivTest extends TestCase
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

    public function test_report_renders_un_and_cj_lines_without_error(): void
    {
        $manifest = $this->makeManifestWithLines([
            // UN con factor → se descompone (258 = 2 cajas + 66 sueltas).
            [
                'product_id' => '80800013',
                'product_description' => 'PASTA NORMAL 8X12X87GR',
                'unit_sale' => 'UN',
                'quantity_box' => 0,
                'quantity_fractions' => 258,
                'conversion_factor' => 96,
                'total' => 2303.98,
            ],
            // CJ → cajas reales.
            [
                'product_id' => '80800013',
                'product_description' => 'PASTA NORMAL 8X12X87GR',
                'unit_sale' => 'CJ',
                'quantity_box' => 11,
                'quantity_fractions' => 11 * 96,
                'conversion_factor' => 96,
                'total' => 8939.48,
            ],
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        // El producto aparece en el reporte.
        $response->assertSee('80800013', false);
        $response->assertSee('PASTA NORMAL 8X12X87GR', false);
    }

    public function test_report_renders_when_factor_is_missing(): void
    {
        // Producto sin factor útil (1): no debe romper, queda todo en unidades.
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '99999999',
                'product_description' => 'PRODUCTO SIN FACTOR',
                'unit_sale' => 'UN',
                'quantity_box' => 0,
                'quantity_fractions' => 7,
                'conversion_factor' => 1,
                'total' => 100.00,
            ],
        ]);

        $this->renderReport($manifest)
            ->assertOk()
            ->assertSee('99999999', false);
    }
}
