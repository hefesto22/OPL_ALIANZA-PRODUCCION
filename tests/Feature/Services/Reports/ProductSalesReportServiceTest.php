<?php

namespace Tests\Feature\Services\Reports;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reports\ProductSalesFilters;
use App\Services\Reports\ProductSalesReportService;
use App\Services\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Reporte de ventas por producto — la matemática.
 *
 * Contrato (Mauricio, 2026-10-25): por producto, Entró · de eso Bonificación ·
 * Devuelto · Neto movido. La bonificación NO resta; solo resta lo devuelto.
 * Las devoluciones que cuentan son las de las facturas del período, aunque se
 * registren después. Las devoluciones se crean con ReturnService (el flujo
 * real) para que returned_quantity y return_lines salgan como en producción.
 */
class ProductSalesReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductSalesReportService $service;

    private Warehouse $oac;

    private Warehouse $oas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProductSalesReportService::class);
        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();
    }

    // ── El ejemplo de la pasta ──────────────────────────────────────

    public function test_ejemplo_pasta_entraron_1000_bonif_50_devueltas_50_neto_950(): void
    {
        // 950 vendidas (L 10 c/u) + 50 que vinieron bonificadas = 1,000 entraron.
        $sold = $this->line($this->invoice($this->oac, '2026-03-10', 9500.00), [
            'product_id' => '50010020',
            'product_description' => 'PASTA SPAGHETTI 200G',
            'quantity_fractions' => 950,
            'total' => 9500.00,
        ]);
        $this->line($this->invoice($this->oac, '2026-03-15', 0.00), [
            'product_id' => '50010020',
            'product_description' => 'PASTA SPAGHETTI 200G',
            'quantity_fractions' => 50,
            'total' => 0,
        ]);

        // Se devuelven 50 de las vendidas: 2 cajas (×20) + 10 sueltas.
        $this->returnFrom($sold, boxes: 2, units: 10);

        $report = $this->service->generate($this->filters('2026-03-01', '2026-03-31'));
        $row = $this->row($report, '50010020');

        $this->assertSame(1000, $row['entered_units']);
        $this->assertSame(50, $row['bonus_units']);
        $this->assertSame(50, $row['returned_units']);
        $this->assertSame(950, $row['net_units']);

        // Factor 20: 1,000 = 50 cajas; 950 = 47 cajas + 10 sueltas.
        $this->assertSame('50 cj', $row['entered']['label']);
        $this->assertSame('2 cj + 10 u', $row['bonus']['label']);
        $this->assertSame('2 cj + 10 u', $row['returned']['label']);
        $this->assertSame('47 cj + 10 u', $row['net']['label']);
        $this->assertSame(5.0, $row['return_pct']);

        $this->assertEqualsWithDelta(9500.00, $row['billed_amount'], 0.001);
        $this->assertEqualsWithDelta(500.00, $row['returned_amount'], 0.001);
        $this->assertEqualsWithDelta(9000.00, $row['net_amount'], 0.001);

        $totals = $report['totals'];
        $this->assertSame(2, $totals['invoices_count']);
        $this->assertSame(1, $totals['products_count']);
        $this->assertEqualsWithDelta(9500.00, $totals['billed_total'], 0.001);
        $this->assertEqualsWithDelta(500.00, $totals['returned_total'], 0.001);
        $this->assertEqualsWithDelta(9000.00, $totals['net_total'], 0.001);
    }

    // ── Qué entra al período ────────────────────────────────────────

    public function test_solo_cuentan_las_facturas_dentro_del_rango(): void
    {
        $this->line($this->invoice($this->oac, '2026-02-28', 100.00), ['product_id' => 'FEB', 'quantity_fractions' => 10, 'total' => 100.00]);
        $this->line($this->invoice($this->oac, '2026-03-01', 100.00), ['product_id' => 'MAR-1', 'quantity_fractions' => 10, 'total' => 100.00]);
        $this->line($this->invoice($this->oac, '2026-03-31', 100.00), ['product_id' => 'MAR-31', 'quantity_fractions' => 10, 'total' => 100.00]);
        $this->line($this->invoice($this->oac, '2026-04-01', 100.00), ['product_id' => 'ABR', 'quantity_fractions' => 10, 'total' => 100.00]);

        $report = $this->service->generate($this->filters('2026-03-01', '2026-03-31'));

        $this->assertSame(['MAR-1', 'MAR-31'], array_column($report['rows'], 'product_id'));
    }

    public function test_por_fecha_del_manifiesto_cuenta_el_dia_de_carga_no_el_de_la_factura(): void
    {
        // Factura del 28 de febrero que llegó en el manifiesto del 2 de marzo.
        $manifest = Manifest::factory()->create(['warehouse_id' => $this->oac->id, 'date' => '2026-03-02']);
        $this->line($this->invoice($this->oac, '2026-02-28', 100.00, $manifest), ['product_id' => 'LLEGO-MARZO', 'quantity_fractions' => 10, 'total' => 100.00]);

        $byInvoice = $this->service->generate($this->filters('2026-03-01', '2026-03-31'));
        $byManifest = $this->service->generate($this->filters('2026-03-01', '2026-03-31', ProductSalesFilters::DATE_FIELD_MANIFEST));

        $this->assertSame([], $byInvoice['rows']);
        $this->assertSame(['LLEGO-MARZO'], array_column($byManifest['rows'], 'product_id'));
    }

    public function test_facturas_rechazadas_y_borradas_no_cuentan(): void
    {
        $this->line($this->invoice($this->oac, '2026-03-10', 100.00), ['product_id' => 'VALIDA', 'quantity_fractions' => 10, 'total' => 100.00]);
        $this->line($this->invoice($this->oac, '2026-03-10', 100.00, status: 'rejected'), ['product_id' => 'RECHAZADA', 'quantity_fractions' => 10, 'total' => 100.00]);
        $deleted = $this->invoice($this->oac, '2026-03-10', 100.00);
        $this->line($deleted, ['product_id' => 'BORRADA', 'quantity_fractions' => 10, 'total' => 100.00]);
        $deleted->delete();

        $report = $this->service->generate($this->filters('2026-03-01', '2026-03-31'));

        $this->assertSame(['VALIDA'], array_column($report['rows'], 'product_id'));
        $this->assertSame(1, $report['totals']['invoices_count']);
        $this->assertEqualsWithDelta(100.00, $report['totals']['billed_total'], 0.001);
    }

    public function test_solo_las_bodegas_pedidas(): void
    {
        $this->line($this->invoice($this->oac, '2026-03-10', 100.00), ['product_id' => 'DE-OAC', 'quantity_fractions' => 10, 'total' => 100.00]);
        $this->line($this->invoice($this->oas, '2026-03-10', 100.00), ['product_id' => 'DE-OAS', 'quantity_fractions' => 10, 'total' => 100.00]);

        $report = $this->service->generate($this->filters('2026-03-01', '2026-03-31', warehouseIds: [$this->oas->id]));

        $this->assertSame(['DE-OAS'], array_column($report['rows'], 'product_id'));
        $this->assertSame(['OAS'], array_column($report['warehouses'], 'code'));
    }

    // ── Devoluciones ────────────────────────────────────────────────

    public function test_devolucion_cancelada_no_resta(): void
    {
        $line = $this->line($this->invoice($this->oac, '2026-03-10', 200.00), ['product_id' => 'CANCELADA', 'quantity_fractions' => 20, 'total' => 200.00]);
        $return = $this->returnFrom($line, boxes: 0, units: 5);

        app(ReturnService::class)->cancelReturn($return, 'Error de captura');

        $row = $this->row($this->service->generate($this->filters('2026-03-01', '2026-03-31')), 'CANCELADA');

        $this->assertSame(0, $row['returned_units']);
        $this->assertSame(20, $row['net_units']);
        $this->assertEqualsWithDelta(0.0, $row['returned_amount'], 0.001);
    }

    public function test_devolucion_registrada_despues_del_periodo_cuenta_para_su_factura(): void
    {
        // La factura es de marzo; la devolución se registra hoy (después).
        $line = $this->line($this->invoice($this->oac, '2026-03-30', 200.00), ['product_id' => 'TARDIA', 'quantity_fractions' => 20, 'total' => 200.00]);
        $this->returnFrom($line, boxes: 0, units: 4);

        $row = $this->row($this->service->generate($this->filters('2026-03-01', '2026-03-31')), 'TARDIA');

        $this->assertSame(4, $row['returned_units']);
        $this->assertEqualsWithDelta(40.00, $row['returned_amount'], 0.001);
    }

    // ── Cantidades ──────────────────────────────────────────────────

    public function test_linea_mixta_cruda_no_pierde_la_caja(): void
    {
        // Jaremar: 1 caja + 56 sueltas en la misma línea, con fracciones crudas.
        $this->line($this->invoice($this->oac, '2026-03-10', 152.00), [
            'product_id' => 'MIXTA',
            'unit_sale' => 'UN',
            'quantity_box' => 1,
            'quantity_fractions' => 56,
            'conversion_factor' => 96,
            'total' => 152.00,
        ]);

        $row = $this->row($this->service->generate($this->filters('2026-03-01', '2026-03-31')), 'MIXTA');

        $this->assertSame(152, $row['entered_units']);
        $this->assertSame('1 cj + 56 u', $row['entered']['label']);
    }

    public function test_un_producto_en_cajas_y_en_unidades_sale_en_una_sola_fila(): void
    {
        $this->line($this->invoice($this->oac, '2026-03-10', 240.00), ['product_id' => 'ACEITE', 'unit_sale' => 'CJ', 'quantity_box' => 1, 'quantity_fractions' => 24, 'conversion_factor' => 24, 'total' => 240.00]);
        $this->line($this->invoice($this->oac, '2026-03-11', 50.00), ['product_id' => 'ACEITE', 'unit_sale' => 'UN', 'quantity_box' => 0, 'quantity_fractions' => 5, 'conversion_factor' => 24, 'total' => 50.00]);

        $report = $this->service->generate($this->filters('2026-03-01', '2026-03-31'));
        $row = $this->row($report, 'ACEITE');

        $this->assertCount(1, $report['rows']);
        $this->assertSame('CJ/UN', $row['unit_sale']);
        $this->assertSame(29, $row['entered_units']);
        $this->assertSame('1 cj + 5 u', $row['entered']['label']);
    }

    public function test_sin_factor_de_caja_todo_sale_en_unidades(): void
    {
        $this->line($this->invoice($this->oac, '2026-03-10', 35.00), ['product_id' => 'SUELTO', 'quantity_box' => 0, 'quantity_fractions' => 35, 'conversion_factor' => 1, 'total' => 35.00]);

        $row = $this->row($this->service->generate($this->filters('2026-03-01', '2026-03-31')), 'SUELTO');

        $this->assertSame('35 u', $row['entered']['label']);
        $this->assertSame('—', $row['bonus']['label']);
    }

    // ── Aviso de ventana abierta ────────────────────────────────────

    public function test_avisa_cuando_un_manifiesto_del_periodo_aun_acepta_devoluciones(): void
    {
        $open = Manifest::factory()->create(['warehouse_id' => $this->oac->id, 'returns_deadline_at' => now()->addDays(3)]);
        $closed = Manifest::factory()->create(['warehouse_id' => $this->oac->id, 'returns_deadline_at' => now()->subDay()]);
        // Sin límite (anterior a la regla): el hook de creación le pondría
        // fecha, así que se deja en NULL directo en la base.
        $noLimit = Manifest::factory()->create(['warehouse_id' => $this->oac->id]);
        Manifest::query()->whereKey($noLimit->id)->update(['returns_deadline_at' => null]);

        foreach ([$open, $closed, $noLimit] as $manifest) {
            $this->invoice($this->oac, '2026-03-10', 10.00, $manifest);
        }

        $report = $this->service->generate($this->filters('2026-03-01', '2026-03-31'));

        $this->assertSame(1, $report['open_return_windows']);
    }

    // ── Bodegas por usuario ─────────────────────────────────────────

    public function test_usuario_de_bodega_solo_consulta_las_suyas(): void
    {
        $user = User::factory()->forWarehouse($this->oac)->create();

        $this->assertSame([$this->oac->id], $this->service->resolveWarehouseIds($user, []));
        $this->assertSame([$this->oac->id], $this->service->resolveWarehouseIds($user, [$this->oas->id]));
        $this->assertSame([$this->oac->id], array_keys($this->service->warehouseOptions($user)));
    }

    public function test_usuario_global_consulta_cualquiera_o_todas(): void
    {
        $user = User::factory()->create();
        $all = [$this->oac->id, $this->oas->id];
        sort($all);

        $this->assertSame($all, $this->service->resolveWarehouseIds($user, []));
        $this->assertSame([$this->oas->id], $this->service->resolveWarehouseIds($user, [$this->oas->id]));
    }

    // ── Filtros y orden ─────────────────────────────────────────────

    public function test_rango_de_mas_de_tres_meses_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El rango máximo es de 3 meses');

        $this->filters('2026-01-01', '2026-04-30');
    }

    public function test_fecha_inicial_posterior_a_la_final_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->filters('2026-03-31', '2026-03-01');
    }

    public function test_fecha_inexistente_se_rechaza(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->filters('2026-02-31', '2026-03-31');
    }

    public function test_un_trimestre_completo_si_se_puede(): void
    {
        $filters = $this->filters('2026-07-01', '2026-09-30');

        $this->assertSame('2026-09-30', $filters->toArray()['date_to']);
    }

    public function test_ordenes_disponibles(): void
    {
        $rows = [
            ['product_id' => 'B', 'description' => 'ZETA', 'net_amount' => 50.0, 'returned_amount' => 9.0, 'returned_units' => 1],
            ['product_id' => 'A', 'description' => 'ALFA', 'net_amount' => 10.0, 'returned_amount' => 0.0, 'returned_units' => 0],
            ['product_id' => 'C', 'description' => 'MEDIO', 'net_amount' => 90.0, 'returned_amount' => 20.0, 'returned_units' => 2],
        ];

        $this->assertSame(['C', 'B', 'A'], array_column(ProductSalesReportService::sortRows($rows, ProductSalesReportService::SORT_NET_AMOUNT), 'product_id'));
        $this->assertSame(['C', 'B', 'A'], array_column(ProductSalesReportService::sortRows($rows, ProductSalesReportService::SORT_RETURNED), 'product_id'));
        $this->assertSame(['A', 'B', 'C'], array_column(ProductSalesReportService::sortRows($rows, ProductSalesReportService::SORT_CODE), 'product_id'));
        $this->assertSame(['A', 'C', 'B'], array_column(ProductSalesReportService::sortRows($rows, ProductSalesReportService::SORT_DESCRIPTION), 'product_id'));
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * @param  list<int>|null  $warehouseIds
     */
    private function filters(string $from, string $to, string $dateField = ProductSalesFilters::DATE_FIELD_INVOICE, ?array $warehouseIds = null): ProductSalesFilters
    {
        return ProductSalesFilters::fromArray([
            'date_from' => $from,
            'date_to' => $to,
            'date_field' => $dateField,
            'warehouse_ids' => $warehouseIds ?? [$this->oac->id, $this->oas->id],
        ]);
    }

    /**
     * Factura con fecha fija. Su manifiesto, si no se pasa uno, es de HOY: así
     * la ventana de devoluciones (7 días hábiles desde la llegada, la pone el
     * hook de Manifest) está abierta y ReturnService acepta la devolución.
     */
    private function invoice(Warehouse $warehouse, string $date, float $total, ?Manifest $manifest = null, string $status = 'imported'): Invoice
    {
        $manifest ??= Manifest::factory()->create(['warehouse_id' => $warehouse->id, 'date' => now()->toDateString()]);

        return Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => $date,
            'status' => $status,
            'total' => $total,
        ]);
    }

    /**
     * Línea en unidades sueltas (factor 20) salvo que se pida otra cosa.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function line(Invoice $invoice, array $attributes): InvoiceLine
    {
        return InvoiceLine::factory()->for($invoice, 'invoice')->create(array_merge([
            'product_description' => 'PRODUCTO '.($attributes['product_id'] ?? ''),
            'unit_sale' => 'UN',
            'quantity_box' => 0,
            'quantity_min_sale' => $attributes['quantity_fractions'] ?? 0,
            'conversion_factor' => 20,
            'subtotal' => $attributes['total'] ?? 0,
            'tax' => 0,
        ], $attributes));
    }

    private function returnFrom(InvoiceLine $line, int $boxes, int $units): InvoiceReturn
    {
        return app(ReturnService::class)->createReturn([
            'invoice_id' => $line->invoice_id,
            'return_reason_id' => ReturnReason::factory()->create()->id,
            'return_date' => now()->toDateString(),
            'created_by' => User::factory()->create()->id,
            'lines' => [
                [
                    'invoice_line_id' => $line->id,
                    'line_number' => $line->line_number,
                    'product_id' => $line->product_id,
                    'product_description' => $line->product_description,
                    'quantity_box' => $boxes,
                    'quantity' => $units,
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function row(array $report, string $productId): array
    {
        $rows = array_values(array_filter($report['rows'], fn (array $row): bool => $row['product_id'] === $productId));

        $this->assertCount(1, $rows, "Se esperaba una fila para {$productId}");

        return $rows[0];
    }
}
