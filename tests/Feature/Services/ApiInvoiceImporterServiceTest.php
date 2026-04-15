<?php

namespace Tests\Feature\Services;

use App\Models\ApiInvoiceImport;
use App\Models\ApiInvoiceImportConflict;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ApiInvoiceImporterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests para ApiInvoiceImporterService::processBatch().
 *
 * Se llama directamente al service (no a través del controller) para
 * aislar la lógica de negocio de auth / hash-dedup / rate-limit, que
 * ya están cubiertos en ManifestApiControllerInsertTest.
 *
 * Todos los tests golpean Postgres real con RefreshDatabase. Se usa
 * Notification::fake() para silenciar notifyWarehouseUsers() y evitar
 * dependencias en el seeder de roles/usuarios de bodega.
 */
class ApiInvoiceImporterServiceTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $oac;

    private Warehouse $oas;

    private Warehouse $oao;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // Roles necesarios para activity log internos
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin',       'guard_name' => 'web']);

        $this->supplier = Supplier::factory()->create(['is_active' => true]);
        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();
        $this->oao = Warehouse::factory()->oao()->create();
    }

    // ─── Helpers ─────────────────────────────────────────────────

    private function service(): ApiInvoiceImporterService
    {
        return $this->app->make(ApiInvoiceImporterService::class);
    }

    private function importRecord(): ApiInvoiceImport
    {
        return ApiInvoiceImport::create([
            'batch_uuid' => fake()->uuid(),
            'api_key_hint' => 'test***',
            'ip_address' => '127.0.0.1',
            'total_received' => 1,
            'raw_payload' => [],
            'payload_hash' => md5('test'),
            'status' => 'received',
        ]);
    }

    /**
     * Factura mínima válida en formato Jaremar (misma estructura que
     * ManifestApiControllerInsertTest::invoicePayload).
     */
    private function invoicePayload(array $overrides = []): array
    {
        return array_merge([
            'Nfactura' => 'F'.fake()->unique()->numerify('########'),
            'NumeroManifiesto' => 'MAN100001',
            'Total' => 450.0,
            'FechaFactura' => now()->toIso8601String(),
            'Almacen' => 'OAC',
            'Vendedorid' => 'V01',
            'Vendedor' => 'VENDEDOR PRUEBA',
            'Clienteid' => 'C001',
            'Cliente' => 'PULPERIA PRUEBA',
            'Rtn' => '',
            'TipoPago' => 'CONTADO',
            'DiasCred' => 0,
            'TipoFactura' => 'FAC',
            'EstadoFactura' => 1,
            'NumeroFacturaLX' => 'LX'.fake()->unique()->numerify('######'),
            'NumeroPedido' => 'PED'.fake()->unique()->numerify('######'),
            'NumeroRuta' => '001',
            'Direccion' => 'COL. TEST',
            'EntregarA' => 'PULPERIA PRUEBA',
            'LineasFactura' => [[
                'ProductoId' => 'ART-001',
                'ProductoDesc' => 'PRODUCTO PRUEBA',
                'NumeroLinea' => 1,
                'Total' => 450.0,
                'Precio' => 15.0,
                'Subtotal' => 450.0,
                'Costo' => 0.0,
                'CantidadFracciones' => 30.0,
                'CantidadDecimal' => 30.0,
                'CantidadCaja' => 0.0,
                'FactorConversion' => 1,
                'UniVenta' => 'UN',
                'TipoProducto' => 'A',
                'Descuento' => 0.0,
                'Impuesto' => 0.0,
                'Impuesto18' => 0.0,
                'PorcentajeDescuento' => 0.0,
                'PorcentajeImpuesto' => 0.0,
                'CantidadUnidadMinVenta' => 30.0,
                'PrecioUnidadMinVenta' => 15.0,
                'Peso' => 0.0,
                'Volumen' => 0.0,
                'Id' => fake()->unique()->numberBetween(1, 999999),
                'InvoiceId' => fake()->numberBetween(1, 999999),
            ]],
        ], $overrides);
    }

    // ═══════════════════════════════════════════════════════════════
    //  1. HAPPY PATH
    // ═══════════════════════════════════════════════════════════════

    public function test_empty_batch_returns_zero_summary(): void
    {
        $summary = $this->service()->processBatch([], $this->importRecord());

        $this->assertSame(0, $summary['invoices_inserted']);
        $this->assertSame(0, $summary['invoices_updated']);
        $this->assertSame(0, $summary['invoices_unchanged']);
        $this->assertSame(0, $summary['invoices_pending_review']);
        $this->assertSame(0, $summary['invoices_rejected']);
    }

    public function test_new_invoice_creates_manifest_invoice_and_lines(): void
    {
        $invoice = $this->invoicePayload([
            'Nfactura' => 'F-HAPPY-001',
            'NumeroManifiesto' => 'MAN-HAPPY-01',
            'Total' => 900.0,
        ]);

        $summary = $this->service()->processBatch([$invoice], $this->importRecord());

        $this->assertSame(1, $summary['invoices_inserted']);

        // Manifiesto creado con supplier activo
        $manifest = Manifest::where('number', 'MAN-HAPPY-01')->first();
        $this->assertNotNull($manifest);
        $this->assertSame('imported', $manifest->status);
        $this->assertSame($this->supplier->id, $manifest->supplier_id);

        // Factura persistida con warehouse correcto
        $inv = Invoice::where('invoice_number', 'F-HAPPY-001')->first();
        $this->assertNotNull($inv);
        $this->assertSame($manifest->id, $inv->manifest_id);
        $this->assertSame($this->oac->id, $inv->warehouse_id);
        $this->assertEquals(900.0, (float) $inv->total);

        // Línea insertada
        $this->assertSame(1, InvoiceLine::where('invoice_id', $inv->id)->count());

        // Conteo por bodega
        $this->assertSame(1, $summary['inserted_warehouse_counts'][$this->oac->id] ?? 0);
    }

    public function test_multiple_manifests_aggregate_warehouse_counts(): void
    {
        $inv1 = $this->invoicePayload([
            'NumeroManifiesto' => 'MAN-A',
            'Almacen' => 'OAC',
        ]);
        $inv2 = $this->invoicePayload([
            'NumeroManifiesto' => 'MAN-B',
            'Almacen' => 'OAC',
        ]);
        $inv3 = $this->invoicePayload([
            'NumeroManifiesto' => 'MAN-B',
            'Almacen' => 'OAS',
        ]);

        $summary = $this->service()->processBatch([$inv1, $inv2, $inv3], $this->importRecord());

        $this->assertSame(3, $summary['invoices_inserted']);
        $this->assertSame(2, $summary['inserted_warehouse_counts'][$this->oac->id]);
        $this->assertSame(1, $summary['inserted_warehouse_counts'][$this->oas->id]);
    }

    // ═══════════════════════════════════════════════════════════════
    //  2. WAREHOUSE VALIDATION
    // ═══════════════════════════════════════════════════════════════

    public function test_unknown_warehouse_rejects_entire_manifest_group(): void
    {
        $inv1 = $this->invoicePayload([
            'NumeroManifiesto' => 'MAN-BAD',
            'Almacen' => 'XXX',
        ]);
        $inv2 = $this->invoicePayload([
            'NumeroManifiesto' => 'MAN-BAD',
            'Almacen' => 'OAC',   // válida, pero comparte manifiesto
        ]);

        $summary = $this->service()->processBatch([$inv1, $inv2], $this->importRecord());

        // Ambas rechazadas (toda el grupo del manifiesto cae)
        $this->assertSame(2, $summary['invoices_rejected']);
        $this->assertSame(0, $summary['invoices_inserted']);
        $this->assertCount(1, $summary['manifiestos_rechazados']);
        $this->assertSame('MAN-BAD', $summary['manifiestos_rechazados'][0]['manifiesto']);
    }

    public function test_empty_warehouse_rejects_manifest_group(): void
    {
        $inv = $this->invoicePayload([
            'NumeroManifiesto' => 'MAN-EMPTY-WH',
            'Almacen' => '',
        ]);

        $summary = $this->service()->processBatch([$inv], $this->importRecord());

        $this->assertSame(1, $summary['invoices_rejected']);
        $this->assertArrayHasKey('(vacío)', $summary['manifiestos_rechazados'][0]['almacenes_desconocidos']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  3. MANIFEST STATE
    // ═══════════════════════════════════════════════════════════════

    public function test_closed_manifest_rejects_all_invoices(): void
    {
        Manifest::factory()->closed()->create([
            'number' => 'MAN-CLOSED',
            'supplier_id' => $this->supplier->id,
        ]);

        $inv = $this->invoicePayload([
            'NumeroManifiesto' => 'MAN-CLOSED',
        ]);

        $summary = $this->service()->processBatch([$inv], $this->importRecord());

        $this->assertSame(1, $summary['invoices_rejected']);
        $this->assertSame(0, $summary['invoices_inserted']);
        $this->assertNotEmpty($summary['errors']);
        $this->assertStringContainsString('cerrado', $summary['errors'][0]['motivo']);
    }

    public function test_nonexistent_manifest_is_created_automatically(): void
    {
        $inv = $this->invoicePayload([
            'NumeroManifiesto' => 'MAN-AUTO-CREATE',
        ]);

        $this->service()->processBatch([$inv], $this->importRecord());

        $manifest = Manifest::where('number', 'MAN-AUTO-CREATE')->first();
        $this->assertNotNull($manifest);
        $this->assertSame('imported', $manifest->status);
        $this->assertSame($this->supplier->id, $manifest->supplier_id);
    }

    // ═══════════════════════════════════════════════════════════════
    //  4. INVOICE CONFLICTS
    // ═══════════════════════════════════════════════════════════════

    public function test_duplicate_invoice_in_different_manifest_is_rejected(): void
    {
        // Crear manifiesto A con factura F-DUP
        $manifestA = Manifest::factory()->create([
            'number' => 'MAN-A-DUP',
            'supplier_id' => $this->supplier->id,
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifestA->id,
            'warehouse_id' => $this->oac->id,
            'invoice_number' => 'F-DUP',
        ]);

        // Enviar F-DUP en manifiesto B
        $inv = $this->invoicePayload([
            'Nfactura' => 'F-DUP',
            'NumeroManifiesto' => 'MAN-B-DUP',
        ]);

        $summary = $this->service()->processBatch([$inv], $this->importRecord());

        $this->assertSame(1, $summary['invoices_rejected']);
        $this->assertStringContainsString('ya existe', $summary['errors'][0]['motivo']);
    }

    public function test_unchanged_invoice_increments_unchanged_counter(): void
    {
        // Crear manifiesto con factura existente
        $manifest = Manifest::factory()->create([
            'number' => 'MAN-SAME',
            'supplier_id' => $this->supplier->id,
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->oac->id,
            'invoice_number' => 'F-SAME-001',
            'total' => 450.0,
            'client_name' => 'PULPERIA PRUEBA',
            'seller_id' => 'V01',
            'payment_type' => 'CONTADO',
            'credit_days' => 0,
            'invoice_date' => now()->toDateString(),
            'route_number' => '001',
            'isv15' => 0,
            'isv18' => 0,
            'discounts' => 0,
            'importe_gravado' => 0,
            'importe_gravado_isv15' => 0,
            'importe_gravado_total' => 0,
            'importe_exento_total' => 0,
            'importe_exonerado_total' => 0,
            'client_rtn' => '',
            'deliver_to' => 'PULPERIA PRUEBA',
            'seller_name' => 'VENDEDOR PRUEBA',
            'due_date' => null,
        ]);

        // Reenviar misma factura con mismos datos
        $inv = $this->invoicePayload([
            'Nfactura' => 'F-SAME-001',
            'NumeroManifiesto' => 'MAN-SAME',
            'Total' => 450.0,
            'Cliente' => 'PULPERIA PRUEBA',
            'Vendedorid' => 'V01',
            'TipoPago' => 'CONTADO',
            'DiasCred' => 0,
            'FechaFactura' => now()->format('d/m/Y'),
            'NumeroRuta' => '001',
            'Rtn' => '',
            'EntregarA' => 'PULPERIA PRUEBA',
            'Vendedor' => 'VENDEDOR PRUEBA',
        ]);

        $summary = $this->service()->processBatch([$inv], $this->importRecord());

        $this->assertSame(1, $summary['invoices_unchanged']);
        $this->assertSame(0, $summary['invoices_inserted']);
        $this->assertSame(0, $summary['invoices_pending_review']);
    }

    public function test_changed_invoice_creates_conflict_row(): void
    {
        $manifest = Manifest::factory()->create([
            'number' => 'MAN-DIFF',
            'supplier_id' => $this->supplier->id,
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->oac->id,
            'invoice_number' => 'F-DIFF-001',
            'total' => 100.0,
            'client_name' => 'CLIENTE VIEJO',
        ]);

        // Reenviar con total y cliente distintos
        $inv = $this->invoicePayload([
            'Nfactura' => 'F-DIFF-001',
            'NumeroManifiesto' => 'MAN-DIFF',
            'Total' => 999.0,
            'Cliente' => 'CLIENTE NUEVO',
        ]);

        $importRecord = $this->importRecord();
        $summary = $this->service()->processBatch([$inv], $importRecord);

        $this->assertSame(1, $summary['invoices_pending_review']);
        $this->assertCount(1, $summary['warnings']);
        $this->assertStringContainsString('diferencias', $summary['warnings'][0]['mensaje']);

        // Fila de conflicto en BD
        $conflict = ApiInvoiceImportConflict::where('api_invoice_import_id', $importRecord->id)->first();
        $this->assertNotNull($conflict);
        $this->assertSame('F-DIFF-001', $conflict->invoice_number);
        $this->assertSame('MAN-DIFF', $conflict->manifest_number);
    }

    // ═══════════════════════════════════════════════════════════════
    //  5. LINE INSERTION EDGE CASE — BOX PRODUCT
    // ═══════════════════════════════════════════════════════════════

    public function test_box_product_computes_quantity_fractions_from_box_times_factor(): void
    {
        $inv = $this->invoicePayload([
            'Nfactura' => 'F-BOX-001',
            'NumeroManifiesto' => 'MAN-BOX',
            'LineasFactura' => [[
                'ProductoId' => 'ART-CJ-001',
                'ProductoDesc' => 'PRODUCTO CAJA',
                'NumeroLinea' => 1,
                'Total' => 600.0,
                'Precio' => 50.0,
                'Subtotal' => 600.0,
                'Costo' => 0.0,
                'CantidadFracciones' => 0.0,    // <-- CERO: es producto caja
                'CantidadDecimal' => 12.0,
                'CantidadCaja' => 12.0,   // 12 cajas
                'FactorConversion' => 24,     // 24 unidades por caja
                'UniVenta' => 'CJ',
                'TipoProducto' => 'A',
                'Descuento' => 0.0,
                'Impuesto' => 0.0,
                'Impuesto18' => 0.0,
                'PorcentajeDescuento' => 0.0,
                'PorcentajeImpuesto' => 0.0,
                'CantidadUnidadMinVenta' => 12.0,
                'PrecioUnidadMinVenta' => 50.0,
                'Peso' => 0.0,
                'Volumen' => 0.0,
                'Id' => 88001,
                'InvoiceId' => 88001,
            ]],
        ]);

        $this->service()->processBatch([$inv], $this->importRecord());

        $invoiceId = Invoice::where('invoice_number', 'F-BOX-001')->value('id');
        $line = InvoiceLine::where('invoice_id', $invoiceId)->first();

        // 12 cajas * 24 factor = 288 fracciones
        $this->assertEquals(288.0, (float) $line->quantity_fractions);
        $this->assertEquals(12.0, (float) $line->quantity_box);
        $this->assertSame(24, $line->conversion_factor);
    }

    // ═══════════════════════════════════════════════════════════════
    //  6. TOTALS RECALCULATION
    // ═══════════════════════════════════════════════════════════════

    public function test_manifest_totals_are_recalculated_after_insert(): void
    {
        $inv1 = $this->invoicePayload([
            'NumeroManifiesto' => 'MAN-TOTALS',
            'Total' => 200.0,
            'Almacen' => 'OAC',
        ]);
        $inv2 = $this->invoicePayload([
            'NumeroManifiesto' => 'MAN-TOTALS',
            'Total' => 300.0,
            'Almacen' => 'OAC',
        ]);

        $this->service()->processBatch([$inv1, $inv2], $this->importRecord());

        $manifest = Manifest::where('number', 'MAN-TOTALS')->first();

        // recalculateTotals() suma total de facturas con warehouse_id
        $this->assertEquals(500.0, (float) $manifest->total_invoices);
        $this->assertSame(2, $manifest->invoices_count);
    }

    // ═══════════════════════════════════════════════════════════════
    //  7. validateManifestDatesForController
    // ═══════════════════════════════════════════════════════════════

    public function test_validate_dates_marks_new_manifest_as_valid(): void
    {
        $invoices = [$this->invoicePayload(['NumeroManifiesto' => 'MAN-NEW-DATE'])];

        $result = $this->service()->validateManifestDatesForController(
            ['MAN-NEW-DATE'],
            $invoices,
        );

        $this->assertFalse($result['tiene_errores']);
        $this->assertCount(1, $result['manifiestos_validos']);
        $this->assertSame('nuevo', $result['manifiestos_validos'][0]['tipo']);
        $this->assertEmpty($result['manifiestos_invalidos']);
    }

    public function test_validate_dates_marks_today_manifest_as_valid(): void
    {
        Manifest::factory()->create([
            'number' => 'MAN-TODAY',
            'supplier_id' => $this->supplier->id,
            'created_at' => now(),
        ]);

        $invoices = [$this->invoicePayload(['NumeroManifiesto' => 'MAN-TODAY'])];

        $result = $this->service()->validateManifestDatesForController(
            ['MAN-TODAY'],
            $invoices,
        );

        $this->assertFalse($result['tiene_errores']);
        $this->assertSame('existente_hoy', $result['manifiestos_validos'][0]['tipo']);
    }

    public function test_validate_dates_marks_yesterday_manifest_as_invalid(): void
    {
        Manifest::factory()->create([
            'number' => 'MAN-YESTERDAY',
            'supplier_id' => $this->supplier->id,
            'created_at' => now()->subDay(),
        ]);

        $invoices = [$this->invoicePayload(['NumeroManifiesto' => 'MAN-YESTERDAY'])];

        $result = $this->service()->validateManifestDatesForController(
            ['MAN-YESTERDAY'],
            $invoices,
        );

        $this->assertTrue($result['tiene_errores']);
        $this->assertCount(1, $result['manifiestos_invalidos']);
        $this->assertSame('MAN-YESTERDAY', $result['manifiestos_invalidos'][0]['manifiesto']);
        $this->assertEmpty($result['manifiestos_validos']);
    }
}
