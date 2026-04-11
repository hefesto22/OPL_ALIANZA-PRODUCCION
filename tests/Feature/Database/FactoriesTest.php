<?php

namespace Tests\Feature\Database;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnReason;
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
        $manifest  = Manifest::factory()->create();
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
}
