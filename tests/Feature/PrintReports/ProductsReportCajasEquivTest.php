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
        // UDC declara AMBAS presentaciones cuando la fila mezcla CJ y UN.
        $response->assertSee('CJ/UN', false);
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

    /**
     * Extrae el valor de una summary card del HTML renderizado.
     */
    private function assertSummaryCard(string $content, string $label, string $expectedValue): void
    {
        $pattern = '/'.preg_quote($label, '/').'<\/div>\s*<div class="value">'.preg_quote($expectedValue, '/').'<\/div>/';

        $this->assertMatchesRegularExpression(
            $pattern,
            $content,
            "La summary card '{$label}' no muestra el valor esperado '{$expectedValue}'."
        );
    }

    public function test_mixed_line_does_not_lose_boxes(): void
    {
        // REGRESIÓN (bug reportado 2026-07-01): línea MIXTA de Jaremar
        // (CantidadCaja>0 Y CantidadFracciones>0). El importador API solo
        // normalizaba fractions cuando fracciones==0, así que en líneas mixtas
        // quantity_fractions trae SOLO las sueltas y las cajas viven aparte en
        // quantity_box. El reporte sumaba solo fractions → perdía las cajas.
        //
        // Caso del usuario: 2 CJ (factor 96) + 100 sueltas = 292 unidades
        //   → debe mostrar 3 cajas y 4 unidades. (Antes: 1 caja y 4.)
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '01020031',
                'product_description' => 'MANTECA DOMESTICA DORAL 25X908GR',
                'unit_sale' => 'CJ',
                'quantity_box' => 2,
                'quantity_fractions' => 100, // solo sueltas: cajas NO incluidas
                'conversion_factor' => 96,
                'total' => 4000.00,
            ],
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        $content = $response->getContent();
        $this->assertSame(1, substr_count($content, '01020031'));
        $this->assertSummaryCard($content, 'Total Cajas', '3');
        $this->assertSummaryCard($content, 'Total Unidades', '4');
    }

    public function test_raw_cj_line_from_manual_import_does_not_lose_boxes(): void
    {
        // REGRESIÓN: el import manual (ManifestImporterService) guarda
        // CantidadFracciones crudo. Una línea CJ pura llega con fractions=0 y
        // las cajas solo en quantity_box → el reporte mostraba 0 cajas 0 unid.
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '30010015',
                'product_description' => 'ACEITE.DOMEST.CB.DP. 410 mL X24SPOUT',
                'unit_sale' => 'CJ',
                'quantity_box' => 3,
                'quantity_fractions' => 0, // crudo: cajas NO incluidas
                'conversion_factor' => 24,
                'total' => 2293.91,
            ],
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        $this->assertSummaryCard($response->getContent(), 'Total Cajas', '3');
        $this->assertSummaryCard($response->getContent(), 'Total Unidades', '0');
    }

    public function test_normalized_line_is_not_double_counted(): void
    {
        // Guarda de NO-regresión del fix: una línea ya normalizada (import API
        // caso CJ puro: fractions == cajas × factor) NO debe sumar las cajas
        // dos veces. 5 cajas factor 24, fractions=120 → 5 cajas 0 unid (no 10).
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '30010065',
                'product_description' => 'ACEITE DOMESTICO C.B. 2.750 L X6',
                'unit_sale' => 'CJ',
                'quantity_box' => 5,
                'quantity_fractions' => 120, // normalizada: cajas YA incluidas
                'conversion_factor' => 24,
                'total' => 6736.30,
            ],
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        $this->assertSummaryCard($response->getContent(), 'Total Cajas', '5');
        $this->assertSummaryCard($response->getContent(), 'Total Unidades', '0');
    }

    public function test_mixed_and_un_lines_of_same_product_consolidate_with_real_totals(): void
    {
        // Escenario completo del bug real: mismo producto en línea mixta
        // (2 cajas + 10 sueltas, crudo) y línea UN (30 sueltas, factor 25).
        // Total real: 2×25 + 10 + 30 = 90 unidades → 3 cajas y 15 unidades,
        // en UNA sola fila, con los totales en lempiras sumados.
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '01020031',
                'product_description' => 'MANTECA DOMESTICA DORAL 25X908GR',
                'unit_sale' => 'CJ',
                'quantity_box' => 2,
                'quantity_fractions' => 10, // mixta cruda: solo sueltas
                'conversion_factor' => 25,
                'total' => 2124.20,
            ],
            [
                'product_id' => '01020031',
                'product_description' => 'MANTECA DOMESTICA DORAL 25X908GR',
                'unit_sale' => 'UN',
                'quantity_box' => 0,
                'quantity_fractions' => 30,
                'conversion_factor' => 25,
                'total' => 1878.24,
            ],
        ]);

        $response = $this->renderReport($manifest);

        $response->assertOk();
        $content = $response->getContent();
        // Una sola fila consolidada
        $this->assertSame(1, substr_count($content, '01020031'));
        // 3 cajas y 15 unidades reales
        $this->assertSummaryCard($content, 'Total Cajas', '3');
        $this->assertSummaryCard($content, 'Total Unidades', '15');
        // Total en lempiras: suma de ambas líneas (2,124.20 + 1,878.24)
        $response->assertSee('4,002.44', false);
    }

    public function test_partial_report_shows_warning_banner(): void
    {
        // REGRESIÓN (confusión operativa 2026-07-01): un reporte generado desde
        // una selección parcial de facturas se confundió con el manifiesto
        // completo y pareció que el sistema "perdía productos". Todo reporte
        // parcial debe declararse como tal, con el conteo X de Y visible.
        $warehouse = Warehouse::where('code', 'OAC')->first()
            ?? Warehouse::factory()->create(['code' => 'OAC', 'name' => 'OAC']);
        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);

        $invoices = Invoice::factory()->count(3)->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
        ]);
        foreach ($invoices as $invoice) {
            InvoiceLine::factory()->for($invoice, 'invoice')->create([
                'product_id' => '70000001',
                'product_description' => 'PRODUCTO BANNER PARCIAL',
                'unit_sale' => 'UN',
                'quantity_box' => 0,
                'quantity_fractions' => 5,
                'conversion_factor' => 1,
                'total' => 100.00,
            ]);
        }

        // Payload con solo 2 de las 3 facturas → banner de reporte parcial.
        $payload = Crypt::encryptString(json_encode([
            'manifest_id' => $manifest->id,
            'invoice_ids' => $invoices->take(2)->pluck('id')->all(),
        ]));

        $response = $this->actingAs(User::factory()->create())
            ->get('/imprimir/reportes/productos?payload='.urlencode($payload));

        $response->assertOk();
        $response->assertSee('REPORTE PARCIAL', false);
        $response->assertSee('incluye 2 de 3 facturas', false);
    }

    public function test_full_report_has_no_partial_banner(): void
    {
        // Reporte del manifiesto completo (sin invoice_ids ni bodega) → sin banner.
        $manifest = $this->makeManifestWithLines([
            [
                'product_id' => '70000002',
                'product_description' => 'PRODUCTO COMPLETO',
                'unit_sale' => 'UN',
                'quantity_box' => 0,
                'quantity_fractions' => 5,
                'conversion_factor' => 1,
                'total' => 100.00,
            ],
        ]);

        $this->renderReport($manifest)
            ->assertOk()
            ->assertDontSee('REPORTE PARCIAL', false);
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
