<?php

namespace Tests\Feature\Services;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnLine;
use App\Models\ReturnReason;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ReturnExportService;
use App\Services\ReturnExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests para ReturnExportService y ReturnExporter.
 *
 * Estos dos servicios son la salida hacia Jaremar: transforman las
 * devoluciones aprobadas al formato exacto que el ERP de Jaremar
 * espera (JSON, XML o CSV). Si la transformación se rompe, las
 * devoluciones no llegan al proveedor y el ciclo de cobro se traba.
 *
 * ReturnExportService::toJaremarArray() corre contra BD real porque
 * necesita las relaciones (invoice, warehouse, returnReason, lines).
 * ReturnExporter (JSON/XML/CSV) se testea capturando el output del
 * StreamedResponse con ob_start/ob_get_clean.
 */
class ReturnExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse    $warehouse;
    private Manifest     $manifest;
    private Invoice      $invoice;
    private ReturnReason $reason;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin',       'guard_name' => 'web']);

        $supplier        = Supplier::factory()->create(['is_active' => true]);
        $this->warehouse = Warehouse::factory()->oac()->create();

        $this->manifest = Manifest::factory()->create([
            'supplier_id' => $supplier->id,
            'number'      => 'MAN-EXP-001',
        ]);

        $this->invoice = Invoice::factory()->create([
            'manifest_id'    => $this->manifest->id,
            'warehouse_id'   => $this->warehouse->id,
            'invoice_number' => 'F-EXP-001',
        ]);

        $this->reason = ReturnReason::factory()->create([
            'jaremar_id'  => '5001',
            'code'        => 'BE-99',
            'description' => 'Producto dañado en transporte',
        ]);
    }

    private function service(): ReturnExportService
    {
        return $this->app->make(ReturnExportService::class);
    }

    private function exporter(): ReturnExporter
    {
        return $this->app->make(ReturnExporter::class);
    }

    /**
     * Crea una devolución aprobada con líneas, lista para exportar.
     */
    private function makeExportableReturn(array $overrides = [], array $lineOverrides = []): InvoiceReturn
    {
        $return = InvoiceReturn::factory()->approved()->create(array_merge([
            'manifest_id'       => $this->manifest->id,
            'invoice_id'        => $this->invoice->id,
            'return_reason_id'  => $this->reason->id,
            'warehouse_id'      => $this->warehouse->id,
            'manifest_number'   => 'MAN-EXP-001',
            'client_id'         => 'CLI-001',
            'client_name'       => 'PULPERIA EXPORTACION',
            'return_date'       => '2026-04-10',
            'processed_date'    => '2026-04-10',
            'processed_time'    => '14:30:00',
            'total'             => 250.50,
        ], $overrides));

        ReturnLine::create(array_merge([
            'return_id'           => $return->id,
            'invoice_line_id'     => null,
            'line_number'         => 1,
            'product_id'          => 'ART-EXP-001',
            'product_description' => 'PRODUCTO EXPORT PRUEBA',
            'quantity_box'        => 0,
            'quantity'            => 5.0,
            'line_total'          => 250.50,
        ], $lineOverrides));

        return $return;
    }

    // ═══════════════════════════════════════════════════════════════
    //  ReturnExportService::toJaremarArray
    // ═══════════════════════════════════════════════════════════════

    public function test_toJaremarArray_maps_all_fields_correctly(): void
    {
        $return = $this->makeExportableReturn();

        $query  = $this->service()->withRelations(
            InvoiceReturn::where('id', $return->id)
        );
        $result = $this->service()->toJaremarArray($query->get());

        $this->assertCount(1, $result);
        $item = $result[0];

        // Campos principales
        $this->assertSame('F-EXP-001', $item['factura']);
        $this->assertSame('CLI-001', $item['clienteid']);
        $this->assertSame('PULPERIA EXPORTACION', $item['cliente']);
        $this->assertSame('OAC', $item['almacen']);
        $this->assertSame('5001', $item['idConcepto']);
        $this->assertSame('Producto dañado en transporte', $item['concepto']);
        $this->assertSame('MAN-EXP-001', $item['numeroManifiesto']);
        $this->assertSame('250.500000', $item['total']);

        // Fechas en formato ISO sin zona
        $this->assertStringStartsWith('2026-04-10T', $item['fecha']);
        $this->assertStringStartsWith('2026-04-10T', $item['fechaProcesado']);
        $this->assertSame('14:30:00', $item['horaProcesado']);

        // Líneas
        $this->assertCount(1, $item['lineasDevolucion']);
        $line = $item['lineasDevolucion'][0];
        $this->assertSame('ART-EXP-001', $line['productoId']);
        $this->assertSame('PRODUCTO EXPORT PRUEBA', $line['producto']);
        $this->assertSame('5.000000', $line['cantidad']);
        $this->assertSame('1', $line['numeroLinea']);
        $this->assertSame('250.500000', $line['lineTotal']);
    }

    public function test_toJaremarArray_uses_jaremar_return_id_when_present(): void
    {
        $return = $this->makeExportableReturn(['jaremar_return_id' => 'JAR-777']);

        $query  = $this->service()->withRelations(InvoiceReturn::where('id', $return->id));
        $result = $this->service()->toJaremarArray($query->get());

        $this->assertSame('JAR-777', $result[0]['devolucion']);
    }

    public function test_toJaremarArray_falls_back_to_id_when_no_jaremar_id(): void
    {
        $return = $this->makeExportableReturn(['jaremar_return_id' => null]);

        $query  = $this->service()->withRelations(InvoiceReturn::where('id', $return->id));
        $result = $this->service()->toJaremarArray($query->get());

        $this->assertSame((string) $return->id, $result[0]['devolucion']);
    }

    public function test_toJaremarArray_handles_null_processed_fields(): void
    {
        $return = $this->makeExportableReturn([
            'processed_date' => null,
            'processed_time' => null,
        ]);

        $query  = $this->service()->withRelations(InvoiceReturn::where('id', $return->id));
        $result = $this->service()->toJaremarArray($query->get());

        $this->assertNull($result[0]['fechaProcesado']);
        $this->assertNull($result[0]['horaProcesado']);
    }

    public function test_toJaremarArray_maps_multiple_lines(): void
    {
        $return = $this->makeExportableReturn();

        // Agregar segunda línea
        ReturnLine::create([
            'return_id'           => $return->id,
            'invoice_line_id'     => null,
            'line_number'         => 2,
            'product_id'          => 'ART-EXP-002',
            'product_description' => 'SEGUNDO PRODUCTO',
            'quantity_box'        => 0,
            'quantity'            => 10.0,
            'line_total'          => 150.00,
        ]);

        $query  = $this->service()->withRelations(InvoiceReturn::where('id', $return->id));
        $result = $this->service()->toJaremarArray($query->get());

        $this->assertCount(2, $result[0]['lineasDevolucion']);
        $this->assertSame('ART-EXP-002', $result[0]['lineasDevolucion'][1]['productoId']);
    }

    public function test_toJaremarArray_prefers_reason_jaremar_id_over_code(): void
    {
        // La razón ya tiene jaremar_id = '5001'
        $return = $this->makeExportableReturn();

        $query  = $this->service()->withRelations(InvoiceReturn::where('id', $return->id));
        $result = $this->service()->toJaremarArray($query->get());

        $this->assertSame('5001', $result[0]['idConcepto']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  ReturnExportService::n6
    // ═══════════════════════════════════════════════════════════════

    public function test_n6_formats_integer_with_six_decimals(): void
    {
        $this->assertSame('100.000000', $this->service()->n6(100));
    }

    public function test_n6_formats_float_with_six_decimals(): void
    {
        $this->assertSame('250.500000', $this->service()->n6(250.5));
    }

    public function test_n6_formats_zero(): void
    {
        $this->assertSame('0.000000', $this->service()->n6(0));
    }

    public function test_n6_formats_null_as_zero(): void
    {
        $this->assertSame('0.000000', $this->service()->n6(null));
    }

    // ═══════════════════════════════════════════════════════════════
    //  ReturnExporter — JSON output
    // ═══════════════════════════════════════════════════════════════

    public function test_toJson_casts_numeric_strings_to_floats(): void
    {
        $data = [[
            'devolucion' => '1', 'factura' => 'F-001', 'clienteid' => 'C1',
            'cliente' => 'TEST', 'fecha' => '2026-04-10T00:00:00',
            'total' => '250.500000', 'almacen' => 'OAC',
            'idConcepto' => '5001', 'concepto' => 'Daño',
            'numeroManifiesto' => 'MAN-001',
            'fechaProcesado' => '2026-04-10T14:30:00', 'horaProcesado' => '14:30:00',
            'lineasDevolucion' => [[
                'productoId' => 'ART-001', 'producto' => 'PROD',
                'cantidad' => '5.000000', 'numeroLinea' => '1',
                'lineTotal' => '250.500000',
            ]],
        ]];

        $response = $this->exporter()->toJson($data, 'test.json');

        ob_start();
        $response->sendContent();
        $json = ob_get_clean();

        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);

        // total y cantidad deben ser floats en el JSON, no strings
        $this->assertIsFloat($decoded[0]['total']);
        $this->assertEquals(250.5, $decoded[0]['total']);
        $this->assertIsFloat($decoded[0]['lineasDevolucion'][0]['cantidad']);
        $this->assertIsFloat($decoded[0]['lineasDevolucion'][0]['lineTotal']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  ReturnExporter — XML output
    // ═══════════════════════════════════════════════════════════════

    public function test_toXml_returns_valid_xml_with_correct_structure(): void
    {
        $data = [[
            'devolucion' => '1', 'factura' => 'F-001', 'clienteid' => 'C1',
            'cliente' => 'PULPERÍA <TEST>', 'fecha' => '2026-04-10T00:00:00',
            'total' => '250.500000', 'almacen' => 'OAC',
            'idConcepto' => '5001', 'concepto' => 'Daño & transporte',
            'numeroManifiesto' => 'MAN-001',
            'fechaProcesado' => null, 'horaProcesado' => null,
            'lineasDevolucion' => [[
                'productoId' => 'ART-001', 'producto' => 'PROD <A>',
                'cantidad' => '5.000000', 'numeroLinea' => '1',
                'lineTotal' => '250.500000',
            ]],
        ]];

        $response = $this->exporter()->toXml($data, 'test.xml');

        ob_start();
        $response->sendContent();
        $xml = ob_get_clean();

        // XML válido que se puede parsear
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);

        // Estructura correcta
        $this->assertSame('devoluciones', $doc->getName());
        $this->assertCount(1, $doc->devolucion);
        $this->assertSame('F-001', (string) $doc->devolucion->factura);

        // Caracteres especiales escapados correctamente
        $this->assertStringContainsString('PULPERÍA', (string) $doc->devolucion->cliente);

        // Líneas
        $this->assertCount(1, $doc->devolucion->lineasDevolucion->linea);
        $this->assertSame('ART-001', (string) $doc->devolucion->lineasDevolucion->linea->productoId);
    }

    // ═══════════════════════════════════════════════════════════════
    //  ReturnExporter — CSV output
    // ═══════════════════════════════════════════════════════════════

    public function test_toCsv_includes_bom_header_and_expands_lines(): void
    {
        $data = [[
            'devolucion' => '1', 'factura' => 'F-001', 'clienteid' => 'C1',
            'cliente' => 'TEST', 'fecha' => '2026-04-10T00:00:00',
            'total' => '250.500000', 'almacen' => 'OAC',
            'idConcepto' => '5001', 'concepto' => 'Daño',
            'numeroManifiesto' => 'MAN-001',
            'fechaProcesado' => '2026-04-10T14:30:00', 'horaProcesado' => '14:30:00',
            'lineasDevolucion' => [
                [
                    'productoId' => 'ART-001', 'producto' => 'PROD A',
                    'cantidad' => '5.000000', 'numeroLinea' => '1',
                    'lineTotal' => '150.000000',
                ],
                [
                    'productoId' => 'ART-002', 'producto' => 'PROD B',
                    'cantidad' => '3.000000', 'numeroLinea' => '2',
                    'lineTotal' => '100.500000',
                ],
            ],
        ]];

        $response = $this->exporter()->toCsv($data, 'test.csv');

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        // BOM UTF-8 presente
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $lines = explode("\n", trim($csv));
        // Header + 2 filas de datos (una por línea de devolución)
        $this->assertCount(3, $lines);

        // Header contiene campos esperados
        $this->assertStringContainsString('devolucion', $lines[0]);
        $this->assertStringContainsString('productoId', $lines[0]);

        // Cada fila de datos tiene el productoId correspondiente
        $this->assertStringContainsString('ART-001', $lines[1]);
        $this->assertStringContainsString('ART-002', $lines[2]);
    }

    public function test_toCsv_handles_return_with_no_lines(): void
    {
        $data = [[
            'devolucion' => '1', 'factura' => 'F-001', 'clienteid' => 'C1',
            'cliente' => 'TEST', 'fecha' => '2026-04-10T00:00:00',
            'total' => '0.000000', 'almacen' => 'OAC',
            'idConcepto' => '5001', 'concepto' => 'Daño',
            'numeroManifiesto' => 'MAN-001',
            'fechaProcesado' => null, 'horaProcesado' => null,
            'lineasDevolucion' => [],
        ]];

        $response = $this->exporter()->toCsv($data, 'test.csv');

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $lines = explode("\n", trim($csv));
        // Header + 1 fila vacía (sin líneas pero con datos de devolución)
        $this->assertCount(2, $lines);
    }
}
