<?php

namespace Tests\Feature\Services\Reports;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReturn;
use App\Models\InvoiceWarehouseTransfer;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reports\ProductSalesFilters;
use App\Services\Reports\ProductSalesReportService;
use App\Services\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reporte de ventas — sección de facturas trasladadas entre bodegas.
 *
 * Contrato (Mauricio, 2026-10-25): la factura trasladada cuenta como venta de
 * la bodega donde QUEDÓ; el reporte dice "OAI → OAS", quién la movió, cuándo y
 * por qué, y de cada devolución quién la registró, de qué bodega es ese
 * usuario, dónde estaba la factura en ese momento y si fue antes o después.
 */
class ProductSalesTransfersSectionTest extends TestCase
{
    use RefreshDatabase;

    private ProductSalesReportService $service;

    private Warehouse $oai;

    private Warehouse $oas;

    private User $mayra;

    private Invoice $invoice;

    private InvoiceLine $line;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProductSalesReportService::class);
        $this->oai = Warehouse::factory()->oai()->create();
        $this->oas = Warehouse::factory()->oas()->create();
        $this->mayra = User::factory()->create(['name' => 'Mayra Admin']);

        // GAEL: entró como OAI y hoy está en OAS.
        $manifest = Manifest::factory()->create(['warehouse_id' => $this->oai->id, 'date' => now()->toDateString()]);
        $this->invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->oas->id,
            'invoice_number' => '002-001-01-03949657',
            'client_id' => '98061111',
            'client_name' => 'INVERSIONES Y DEPOSITOS GAEL',
            'invoice_date' => now()->toDateString(),
            'total' => 2400.00,
        ]);
        $this->line = InvoiceLine::factory()->for($this->invoice, 'invoice')->create([
            'product_id' => '50010020',
            'product_description' => 'PASTA SPAGHETTI 200G',
            'unit_sale' => 'UN',
            'quantity_box' => 0,
            'quantity_fractions' => 240,
            'conversion_factor' => 24,
            'subtotal' => 2400.00,
            'tax' => 0,
            'total' => 2400.00,
        ]);
    }

    public function test_la_bodega_destino_la_ve_como_recibida_y_le_suma(): void
    {
        $this->transfer(now());

        $report = $this->service->generate($this->filters([$this->oas->id]));
        $received = $report['transfers']['received'];

        $this->assertSame(['50010020'], array_column($report['rows'], 'product_id'));
        $this->assertCount(1, $received);
        $this->assertSame([], $report['transfers']['sent']);
        $this->assertSame('OAI → OAS', $received[0]['chain']);
        $this->assertSame('98061111 · INVERSIONES Y DEPOSITOS GAEL', $received[0]['client']);
        $this->assertSame('Mayra Admin', $received[0]['moves'][0]['by']);
        $this->assertSame('Jaremar la facturó a OAI; la entrega es de Santa Bárbara', $received[0]['moves'][0]['reason']);
        $this->assertSame(1, $report['transfers']['received_count']);
        $this->assertEqualsWithDelta(2400.00, $report['transfers']['received_total'], 0.001);
    }

    public function test_la_bodega_origen_la_ve_como_enviada_y_no_le_suma(): void
    {
        $this->transfer(now());

        $report = $this->service->generate($this->filters([$this->oai->id]));

        $this->assertSame([], $report['rows']);
        $this->assertSame([], $report['transfers']['received']);
        $this->assertCount(1, $report['transfers']['sent']);
        $this->assertSame('OAS', $report['transfers']['sent'][0]['current_warehouse']);
    }

    public function test_con_todas_las_bodegas_sale_una_sola_vez(): void
    {
        $this->transfer(now());

        $report = $this->service->generate($this->filters([$this->oai->id, $this->oas->id]));

        $this->assertCount(1, $report['transfers']['received']);
        $this->assertSame([], $report['transfers']['sent']);
    }

    public function test_cada_devolucion_dice_quien_la_hizo_de_que_bodega_y_si_fue_antes_o_despues(): void
    {
        $bodegueroOai = User::factory()->forWarehouse($this->oai)->create(['name' => 'Luis OAI']);
        $bodegueroOas = User::factory()->forWarehouse($this->oas)->create(['name' => 'Ana OAS']);

        // 1) Devolución registrada en Intibucá ANTES del traslado.
        $this->travelTo(now()->subHours(3));
        $this->registerReturn($bodegueroOai, units: 24);

        // 2) Se traslada a Santa Bárbara.
        $this->travelBack();
        $this->transfer(now()->subHour());

        // 3) Devolución registrada en Santa Bárbara DESPUÉS del traslado.
        $this->registerReturn($bodegueroOas, units: 12);

        $returns = $this->service->generate($this->filters([$this->oas->id]))['transfers']['received'][0]['returns'];

        $this->assertCount(2, $returns);

        $this->assertSame('Luis OAI', $returns[0]['user']);
        $this->assertSame('OAI', $returns[0]['user_warehouses']);
        $this->assertSame('OAI', $returns[0]['registered_in']);
        $this->assertSame('Antes del traslado', $returns[0]['timing']);
        $this->assertSame('PASTA SPAGHETTI 200G', $returns[0]['lines'][0]['description']);
        $this->assertSame('1 cj', $returns[0]['lines'][0]['quantity']);
        $this->assertEqualsWithDelta(240.00, $returns[0]['total'], 0.001);

        $this->assertSame('Ana OAS', $returns[1]['user']);
        $this->assertSame('OAS', $returns[1]['user_warehouses']);
        $this->assertSame('OAS', $returns[1]['registered_in']);
        $this->assertSame('Después del traslado', $returns[1]['timing']);
        $this->assertSame('12 u', $returns[1]['lines'][0]['quantity']);
    }

    public function test_una_devolucion_cancelada_se_lista_pero_no_cuenta(): void
    {
        $this->transfer(now()->subHour());
        $return = $this->registerReturn($this->mayra, units: 24);
        app(ReturnService::class)->cancelReturn($return, 'Error de captura');

        $returns = $this->service->generate($this->filters([$this->oas->id]))['transfers']['received'][0]['returns'];

        $this->assertSame('Cancelada', $returns[0]['status']);
        $this->assertFalse($returns[0]['counts']);
        $this->assertSame('Todas (global)', $returns[0]['user_warehouses']);
    }

    public function test_sin_traslados_la_seccion_queda_vacia(): void
    {
        $report = $this->service->generate($this->filters([$this->oas->id]));

        $this->assertSame([], $report['transfers']['received']);
        $this->assertSame([], $report['transfers']['sent']);
        $this->assertSame(0, $report['transfers']['received_count']);
    }

    /**
     * @param  list<int>  $warehouseIds
     */
    private function filters(array $warehouseIds): ProductSalesFilters
    {
        return ProductSalesFilters::fromArray([
            'date_from' => now()->startOfMonth()->toDateString(),
            'date_to' => now()->endOfMonth()->toDateString(),
            'date_field' => ProductSalesFilters::DATE_FIELD_INVOICE,
            'warehouse_ids' => $warehouseIds,
        ]);
    }

    private function transfer(\DateTimeInterface $at): void
    {
        InvoiceWarehouseTransfer::factory()->create([
            'invoice_id' => $this->invoice->id,
            'from_warehouse_id' => $this->oai->id,
            'to_warehouse_id' => $this->oas->id,
            'transferred_by' => $this->mayra->id,
            'reason' => 'Jaremar la facturó a OAI; la entrega es de Santa Bárbara',
            'transferred_at' => $at,
        ]);
    }

    private function registerReturn(User $user, int $units): InvoiceReturn
    {
        return app(ReturnService::class)->createReturn([
            'invoice_id' => $this->invoice->id,
            'return_reason_id' => ReturnReason::factory()->create()->id,
            'return_date' => now()->toDateString(),
            'created_by' => $user->id,
            'lines' => [
                [
                    'invoice_line_id' => $this->line->id,
                    'line_number' => $this->line->line_number,
                    'product_id' => $this->line->product_id,
                    'product_description' => $this->line->product_description,
                    'quantity_box' => 0,
                    'quantity' => $units,
                ],
            ],
        ]);
    }
}
