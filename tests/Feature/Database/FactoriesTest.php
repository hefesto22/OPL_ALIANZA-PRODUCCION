<?php

namespace Tests\Feature\Database;

use App\Models\ApiInvoiceImport;
use App\Models\ApiInvoiceImportConflict;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ManifestWarehouseTotal;
use App\Models\ReturnLine;
use App\Models\ReturnReason;
use App\Models\Route;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test de las factories de negocio.
 *
 * El objetivo NO es validar lógica de dominio — es confirmar que cada
 * factory produce un registro que pasa los constraints de la base de datos
 * (FKs, unique, not null, enums). Si una migración cambia un campo y la
 * factory se queda desalineada, este test debe romperse primero, antes
 * que cualquier test de servicio que dependa de la factory.
 */
class FactoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_factory_creates_valid_record(): void
    {
        $supplier = Supplier::factory()->create();

        $this->assertNotNull($supplier->id);
        $this->assertTrue($supplier->is_active);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_warehouse_factory_with_oac_state(): void
    {
        $warehouse = Warehouse::factory()->oac()->create();

        $this->assertSame('OAC', $warehouse->code);
        $this->assertSame('Copán', $warehouse->department);
    }

    public function test_warehouse_factory_with_oas_state(): void
    {
        $warehouse = Warehouse::factory()->oas()->create();

        $this->assertSame('OAS', $warehouse->code);
    }

    public function test_warehouse_factory_with_oao_state(): void
    {
        $warehouse = Warehouse::factory()->oao()->create();

        $this->assertSame('OAO', $warehouse->code);
    }

    public function test_return_reason_factory_creates_valid_record(): void
    {
        $reason = ReturnReason::factory()->create();

        $this->assertSame('BE', $reason->category);
        $this->assertStringStartsWith('BE-', $reason->code);
    }

    public function test_manifest_factory_creates_valid_record_with_relations(): void
    {
        $manifest = Manifest::factory()->create();

        $this->assertNotNull($manifest->supplier_id);
        $this->assertNotNull($manifest->warehouse_id);
        $this->assertSame('imported', $manifest->status);
        $this->assertSame(0.0, (float) $manifest->total_invoices);
        $this->assertInstanceOf(Supplier::class, $manifest->supplier);
        $this->assertInstanceOf(Warehouse::class, $manifest->warehouse);
    }

    public function test_manifest_closed_state(): void
    {
        $manifest = Manifest::factory()->closed()->create();

        $this->assertSame('closed', $manifest->status);
        $this->assertNotNull($manifest->closed_at);
        $this->assertTrue($manifest->isClosed());
    }

    public function test_invoice_factory_creates_valid_record(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertNotNull($invoice->manifest_id);
        $this->assertNotNull($invoice->warehouse_id);
        $this->assertSame('imported', $invoice->status);
        $this->assertGreaterThan(0, (float) $invoice->total);
        $this->assertInstanceOf(Manifest::class, $invoice->manifest);
    }

    public function test_invoice_printed_state(): void
    {
        $invoice = Invoice::factory()->printed()->create();

        $this->assertTrue($invoice->is_printed);
        $this->assertNotNull($invoice->printed_at);
    }

    public function test_invoice_return_factory_creates_valid_record(): void
    {
        $return = InvoiceReturn::factory()->create();

        $this->assertNotNull($return->manifest_id);
        $this->assertNotNull($return->invoice_id);
        $this->assertNotNull($return->return_reason_id);
        $this->assertSame('partial', $return->type);
        $this->assertSame('pending', $return->status);
        $this->assertTrue($return->isPending());
    }

    public function test_invoice_return_approved_state(): void
    {
        $return = InvoiceReturn::factory()->approved()->create();

        $this->assertSame('approved', $return->status);
        $this->assertTrue($return->isApproved());
        $this->assertNotNull($return->reviewed_at);
    }

    public function test_invoice_return_rejected_state(): void
    {
        $return = InvoiceReturn::factory()->rejected('Motivo inválido')->create();

        $this->assertSame('rejected', $return->status);
        $this->assertSame('Motivo inválido', $return->rejection_reason);
        $this->assertTrue($return->isRejected());
    }

    public function test_manifest_recalculate_totals_with_factory_data(): void
    {
        // Verifica que el pipeline completo funciona end-to-end: factory crea
        // modelos, se construyen relaciones consistentes, recalculateTotals()
        // (el método optimizado en B1) produce los agregados esperados.
        $manifest = Manifest::factory()->create();
        $warehouse = $manifest->warehouse;

        Invoice::factory()
            ->count(3)
            ->for($manifest, 'manifest')
            ->for($warehouse, 'warehouse')
            ->state(['total' => 1000])
            ->create();

        $manifest->recalculateTotals();
        $manifest->refresh();

        $this->assertSame(3, $manifest->invoices_count);
        $this->assertSame(3000.0, (float) $manifest->total_invoices);
        $this->assertSame(3000.0, (float) $manifest->total_to_deposit);
    }

    public function test_return_line_factory_creates_valid_record(): void
    {
        $line = ReturnLine::factory()->create();

        $this->assertNotNull($line->return_id);
        $this->assertNotNull($line->invoice_line_id);
        $this->assertSame(1, (int) $line->quantity_box);
        $this->assertSame(0.0, (float) $line->quantity);
        $this->assertSame(120.0, (float) $line->line_total);
    }

    public function test_return_line_loose_state(): void
    {
        // Devolución de unidades sueltas, sin cajas enteras.
        $line = ReturnLine::factory()->loose(5, 7.5)->create();

        $this->assertSame(0, (int) $line->quantity_box);
        $this->assertSame(5.0, (float) $line->quantity);
        $this->assertSame(37.5, (float) $line->line_total);
    }

    public function test_return_line_mixed_state(): void
    {
        // 2 cajas × 12 unidades + 3 sueltas = 27 unidades × Q10 = Q270.
        $line = ReturnLine::factory()->mixed(2, 3, 10.0, 12)->create();

        $this->assertSame(2, (int) $line->quantity_box);
        $this->assertSame(3.0, (float) $line->quantity);
        $this->assertSame(270.0, (float) $line->line_total);
    }

    public function test_route_factory_creates_valid_record(): void
    {
        $route = Route::factory()->create();

        $this->assertNotNull($route->warehouse_id);
        $this->assertNotEmpty($route->code);
        $this->assertTrue($route->is_active);
        $this->assertInstanceOf(Warehouse::class, $route->warehouse);
    }

    public function test_route_inactive_state(): void
    {
        $route = Route::factory()->inactive()->create();

        $this->assertFalse($route->is_active);
    }

    public function test_route_without_seller_state(): void
    {
        // Escenario válido en Jaremar: ruta creada sin vendedor todavía asignado.
        $route = Route::factory()->withoutSeller()->create();

        $this->assertNull($route->seller_id);
        $this->assertNull($route->seller_name);
    }

    public function test_manifest_warehouse_total_factory_creates_valid_record(): void
    {
        $mwt = ManifestWarehouseTotal::factory()->create();

        $this->assertNotNull($mwt->manifest_id);
        $this->assertNotNull($mwt->warehouse_id);
        $this->assertSame(0.0, (float) $mwt->total_invoices);
        $this->assertSame(0, $mwt->invoices_count);
        $this->assertSame(0, $mwt->clients_count);
    }

    public function test_manifest_warehouse_total_with_totals_state(): void
    {
        $mwt = ManifestWarehouseTotal::factory()->withTotals()->create();

        $this->assertSame(25000.0, (float) $mwt->total_invoices);
        $this->assertSame(1500.0, (float) $mwt->total_returns);
        $this->assertSame(23500.0, (float) $mwt->total_to_deposit);
        $this->assertSame(10, $mwt->invoices_count);
    }

    public function test_manifest_warehouse_total_settled_state(): void
    {
        $mwt = ManifestWarehouseTotal::factory()->settled()->create();

        $this->assertSame(0.0, (float) $mwt->difference);
        $this->assertSame((float) $mwt->total_to_deposit, (float) $mwt->total_deposited);
    }

    public function test_api_invoice_import_factory_creates_valid_record(): void
    {
        $import = ApiInvoiceImport::factory()->create();

        $this->assertSame('received', $import->status);
        $this->assertNotEmpty($import->batch_uuid);
        $this->assertSame(64, strlen($import->payload_hash));
        $this->assertIsArray($import->raw_payload);
        $this->assertSame(0, $import->invoices_inserted);
    }

    public function test_api_invoice_import_processed_state(): void
    {
        $import = ApiInvoiceImport::factory()->processed()->create();

        $this->assertSame('processed', $import->status);
        $this->assertSame(1, $import->invoices_inserted);
    }

    public function test_api_invoice_import_partial_state(): void
    {
        $import = ApiInvoiceImport::factory()->partial(3)->create();

        $this->assertSame('partial', $import->status);
        $this->assertSame(3, $import->invoices_pending_review);
        $this->assertIsArray($import->warnings);
    }

    public function test_api_invoice_import_failed_state(): void
    {
        $import = ApiInvoiceImport::factory()->failed('Timeout on Jaremar')->create();

        $this->assertSame('failed', $import->status);
        $this->assertSame('Timeout on Jaremar', $import->failure_message);
    }

    public function test_api_invoice_import_conflict_factory_creates_valid_record(): void
    {
        $conflict = ApiInvoiceImportConflict::factory()->create();

        $this->assertNotNull($conflict->api_invoice_import_id);
        $this->assertNotNull($conflict->invoice_id);
        $this->assertSame('pending', $conflict->resolution);
        $this->assertNull($conflict->resolved_by);
        $this->assertNull($conflict->resolved_at);
        $this->assertIsArray($conflict->previous_values);
        $this->assertIsArray($conflict->incoming_values);
    }

    public function test_api_invoice_import_conflict_accepted_state(): void
    {
        $conflict = ApiInvoiceImportConflict::factory()->accepted('Validado por admin')->create();

        $this->assertSame('accepted', $conflict->resolution);
        $this->assertNotNull($conflict->resolved_by);
        $this->assertNotNull($conflict->resolved_at);
        $this->assertSame('Validado por admin', $conflict->resolution_notes);
    }

    public function test_api_invoice_import_conflict_rejected_state(): void
    {
        $conflict = ApiInvoiceImportConflict::factory()->rejected()->create();

        $this->assertSame('rejected', $conflict->resolution);
        $this->assertNotNull($conflict->resolved_by);
        $this->assertNotNull($conflict->resolved_at);
    }
}
