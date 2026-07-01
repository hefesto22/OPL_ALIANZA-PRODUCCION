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

    public function test_un_and_cj_lines_consolidate_into_a_single_row(): void
    {
        // Mismo producto vendido en UN y en CJ → debe salir en UNA sola fila
        // (antes salía partido en dos, lo que confundía a la bodega).
        // Consolidado: 258 (UN) + 11*96 (CJ) = 1314 unidades, factor 96
        //   → 13 cajas y 66 sueltas.
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '80800013',
                'product_description' => 'PASTA NORMAL 8X12X87GR',
                'unit_sale' => 'UN',
                'quantity_box' => 0,
                'quantity_fractions' => 258,
                'conversion_factor' => 96,
                'total' => 2303.98,
            ],
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
        $response->assertSee('PASTA NORMAL 8X12X87GR', false);
        // El código del producto aparece UNA sola vez → una sola fila consolidada.
        $this->assertSame(1, substr_count($response->getContent(), '80800013'));
    }

    public function test_total_general_uses_invoice_total_not_line_sum(): void
    {
        // El total de factura de Jaremar puede no cuadrar exacto con la suma de
        // sus líneas (redondeo del proveedor). El TOTAL GENERAL de la Sublista
        // debe reflejar el VALOR FISCAL de la factura —igual que el checklist y
        // el Total Manifiesto— no la suma de líneas.
        $warehouse = Warehouse::where('code', 'OAC')->first()
            ?? Warehouse::factory()->create(['code' => 'OAC', 'name' => 'OAC']);
        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);

        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'total' => 12345.67,          // total fiscal de la factura
        ]);
        InvoiceLine::factory()->for($invoice, 'invoice')->create([
            'product_id' => '55550001',
            'product_description' => 'PRODUCTO REDONDEO',
            'unit_sale' => 'UN',
            'quantity_box' => 0,
            'quantity_fractions' => 10,
            'conversion_factor' => 1,
            'total' => 12345.00,          // las líneas suman menos (redondeo)
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        // El TOTAL GENERAL muestra el total de FACTURA (12,345.67), no la de líneas.
        $response->assertSee('12,345.67', false);
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
