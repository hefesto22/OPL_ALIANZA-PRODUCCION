<?php

namespace Tests\Feature\Filament;

use App\Exports\ProductSalesExport;
use App\Filament\Pages\Reports\ProductSalesReportPage;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceWarehouseTransfer;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reports\ProductSalesReportService;
use App\Services\ReturnService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pantalla "Reporte de ventas", su vista de impresión y su Excel.
 *
 * Reglas protegidas:
 *   - Solo entra quien tiene ReportProductSales:Invoice (admin y finanzas).
 *   - Un usuario de bodega solo ve sus bodegas: ni el selector, ni un enlace
 *     de impresión armado para otra bodega, ni manipular el estado de
 *     Livewire le muestran datos ajenos.
 *   - Pantalla, impresión y Excel muestran los mismos números.
 */
class ProductSalesReportPageTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $oac;

    private Warehouse $oas;

    private User $admin;

    private User $financeOac;

    private User $encargado;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'finance', 'encargado'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        Permission::create(['name' => ProductSalesReportService::PERMISSION, 'guard_name' => 'web']);
        Role::findByName('admin')->givePermissionTo(ProductSalesReportService::PERMISSION);
        Role::findByName('finance')->givePermissionTo(ProductSalesReportService::PERMISSION);

        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->financeOac = User::factory()->forWarehouse($this->oac)->create(['is_active' => true]);
        $this->financeOac->assignRole('finance');

        $this->encargado = User::factory()->forWarehouse($this->oac)->create(['is_active' => true]);
        $this->encargado->assignRole('encargado');

        // Una venta de este mes en cada bodega.
        $this->sale($this->oac, 'ARR-01', 'ARROZ PRUEBA OAC', 120, 1200.00);
        $this->sale($this->oas, 'FRI-01', 'FRIJOL PRUEBA OAS', 48, 480.00);

        Filament::setCurrentPanel('admin');
    }

    // ── Acceso ──────────────────────────────────────────────────────

    public function test_admin_abre_el_reporte_y_ve_el_mes_en_curso_de_todas_las_bodegas(): void
    {
        $this->actingAs($this->admin)
            ->get(ProductSalesReportPage::getUrl())
            ->assertOk()
            ->assertSee('Reporte de ventas por producto')
            ->assertSee('ARROZ PRUEBA OAC')
            ->assertSee('FRIJOL PRUEBA OAS')
            // Cuadro de arriba: Entró − Devuelto = Se movió, en cajas, unidades y lempiras.
            ->assertSeeInOrder(['Entró', 'Devuelto', 'Se movió', 'De eso bonificación'])
            ->assertSeeInOrder(['Cajas y sueltas', 'En unidades', '168', 'En lempiras', 'L 1,680.00'])
            ->assertDontSee('¿Por qué las cajas no restan exacto?');

        $this->assertSame('/reportes/ventas-por-producto', parse_url(ProductSalesReportPage::getUrl(), PHP_URL_PATH));
    }

    public function test_sin_el_permiso_no_entra_ni_lo_ve_en_el_menu(): void
    {
        $this->actingAs($this->encargado)
            ->get(ProductSalesReportPage::getUrl())
            ->assertForbidden();

        $this->assertFalse(ProductSalesReportPage::canAccess());

        $this->actingAs($this->financeOac);
        $this->assertTrue(ProductSalesReportPage::canAccess());
    }

    // ── Bodegas ─────────────────────────────────────────────────────

    public function test_finanzas_de_una_bodega_solo_ve_la_suya(): void
    {
        $this->actingAs($this->financeOac)
            ->get(ProductSalesReportPage::getUrl())
            ->assertOk()
            ->assertSee('ARROZ PRUEBA OAC')
            ->assertDontSee('FRIJOL PRUEBA OAS');
    }

    public function test_el_navegador_no_puede_cambiar_los_filtros_aplicados(): void
    {
        $this->actingAs($this->financeOac);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(ProductSalesReportPage::class)
            ->set('applied.warehouse_ids', [$this->oas->id]);
    }

    // ── Generar ─────────────────────────────────────────────────────

    public function test_otro_periodo_sin_ventas_muestra_el_aviso_vacio(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ProductSalesReportPage::class)
            ->fillForm([
                'date_from' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                'date_to' => now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ])
            ->call('generate')
            ->assertHasNoFormErrors()
            ->assertDontSee('ARROZ PRUEBA OAC')
            ->assertSee('No hay facturas en este período');
    }

    public function test_rango_de_mas_de_tres_meses_avisa_y_deja_el_reporte_anterior(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ProductSalesReportPage::class)
            ->fillForm([
                'date_from' => '2026-01-01',
                'date_to' => '2026-06-30',
            ])
            ->call('generate')
            ->assertNotified('No se pudo generar el reporte')
            ->assertSet('applied.date_from', now()->startOfMonth()->toDateString())
            ->assertSee('ARROZ PRUEBA OAC');
    }

    // ── Imprimir ────────────────────────────────────────────────────

    public function test_si_una_devolucion_abre_una_caja_el_cuadro_lo_explica(): void
    {
        // 2 cajas de 24 y devuelven 5 sueltas: queda 1 cj + 19 u, una caja se abrió.
        $invoice = $this->sale($this->oac, 'ACE-01', 'ACEITE PRUEBA OAC', 48, 480.00);
        $line = $invoice->lines()->firstOrFail();

        app(ReturnService::class)->createReturn([
            'invoice_id' => $invoice->id,
            'return_reason_id' => ReturnReason::factory()->create()->id,
            'return_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
            'lines' => [[
                'invoice_line_id' => $line->id,
                'line_number' => $line->line_number,
                'product_id' => $line->product_id,
                'product_description' => $line->product_description,
                'quantity_box' => 0,
                'quantity' => 5,
            ]],
        ]);

        $this->actingAs($this->admin)
            ->get(ProductSalesReportPage::getUrl())
            ->assertOk()
            ->assertSee('¿Por qué las cajas no restan exacto?')
            ->assertSee('En 1 producto');

        $this->get(Livewire::test(ProductSalesReportPage::class)->instance()->printUrl())
            ->assertOk()
            ->assertSee('¿Por qué las cajas no restan exacto?')
            ->assertSee('En 1 producto');
    }

    public function test_imprimir_muestra_los_mismos_datos_que_la_pantalla(): void
    {
        $this->actingAs($this->admin);

        $url = Livewire::test(ProductSalesReportPage::class)->instance()->printUrl();

        $this->assertNotNull($url);

        $this->get($url)
            ->assertOk()
            ->assertSee('Reporte de Ventas por Producto')
            ->assertSee('ARROZ PRUEBA OAC')
            ->assertSee('FRIJOL PRUEBA OAS')
            ->assertSee('1,680.00')
            ->assertSeeInOrder(['Entró', 'Devuelto', 'Se movió', 'De eso bonificación'])
            ->assertSeeInOrder(['Cajas y sueltas', 'En unidades', '168', 'En lempiras', 'L 1,680.00']);
    }

    public function test_cantidades_grandes_se_imprimen_completas_sin_recortarse(): void
    {
        // 3,816 cajas + 23 sueltas (factor 24): antes la celda se cortaba en
        // "3,816 cj + 2…". Ahora baja de línea en el "+" sin perder nada.
        $this->sale($this->oac, 'PAS-01', 'PASTA NORMAL 8X12X87GR', 3816 * 24 + 23, 3085964.32);

        $this->actingAs($this->admin)
            ->get($this->printUrlFor([$this->oac->id]))
            ->assertOk()
            ->assertSee('<span class="nw">3,816 cj</span> <span class="nw">+ 23 u</span>', false)
            ->assertSee('3,085,964.32')
            ->assertDontSee('text-overflow', false);
    }

    public function test_pantalla_e_impresion_muestran_la_factura_trasladada(): void
    {
        $oai = Warehouse::factory()->oai()->create();
        $invoice = $this->sale($this->oas, 'GAE-01', 'FRIJOL GAEL', 48, 480.00);
        InvoiceWarehouseTransfer::factory()->create([
            'invoice_id' => $invoice->id,
            'from_warehouse_id' => $oai->id,
            'to_warehouse_id' => $this->oas->id,
            'transferred_by' => $this->admin->id,
            'reason' => 'Jaremar la facturó a OAI por error',
            'transferred_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(ProductSalesReportPage::getUrl())
            ->assertOk()
            ->assertSee('Facturas trasladadas entre bodegas')
            ->assertSee('OAI → OAS')
            ->assertSee('Jaremar la facturó a OAI por error')
            ->assertSee('Productos de esta factura')
            ->assertSee('Resumen de productos trasladados');

        $this->get($this->printUrlFor([$this->oas->id]))
            ->assertOk()
            ->assertSee('OAI → OAS')
            ->assertSee($invoice->invoice_number)
            ->assertSee('Productos de esta factura')
            ->assertSee('Resumen de productos trasladados')
            ->assertSee('Sin devoluciones.');
    }

    public function test_imprimir_sin_permiso_da_403(): void
    {
        $this->actingAs($this->encargado)
            ->get($this->printUrlFor([$this->oac->id]))
            ->assertForbidden();
    }

    public function test_imprimir_con_enlace_alterado_da_403(): void
    {
        $this->actingAs($this->admin)
            ->get(route('reports.product-sales', ['payload' => 'no-es-un-payload']))
            ->assertForbidden();
    }

    public function test_un_enlace_de_otra_bodega_no_le_muestra_datos_ajenos(): void
    {
        $this->actingAs($this->financeOac)
            ->get($this->printUrlFor([$this->oas->id]))
            ->assertOk()
            ->assertSee('ARROZ PRUEBA OAC')
            ->assertDontSee('FRIJOL PRUEBA OAS');
    }

    // ── Excel ───────────────────────────────────────────────────────

    public function test_exportar_excel_va_a_la_cola_de_reportes_con_las_filas_del_reporte(): void
    {
        Excel::fake();
        $this->freezeTime();
        $this->actingAs($this->admin);

        Livewire::test(ProductSalesReportPage::class)
            ->callAction('export')
            ->assertNotified('Exportación en proceso');

        Excel::assertQueued($this->expectedExportPath(), 'local', function (ProductSalesExport $export): bool {
            $codes = $export->sheets()[0]->collection()->pluck(0)->all();

            return $export->queue === 'reports'
                && $codes === ['ARR-01', 'FRI-01', 'TOTAL'];
        });
    }

    public function test_el_excel_se_genera_de_verdad_y_avisa_al_usuario(): void
    {
        Storage::fake('local');
        $this->freezeTime();
        $this->actingAs($this->admin);

        Livewire::test(ProductSalesReportPage::class)->callAction('export');

        // Cola sync en tests: el export y NotifyExportReady ya corrieron.
        Storage::disk('local')->assertExists($this->expectedExportPath());

        $book = IOFactory::load(Storage::disk('local')->path($this->expectedExportPath()));
        $sheet = $book->getSheetByName('Ventas por producto');

        $this->assertNotNull($sheet);
        $this->assertNotNull($book->getSheetByName('Traslados'));
        $this->assertNotNull($book->getSheetByName('Productos trasladados'));

        $this->assertStringContainsString('Reporte de ventas por producto', (string) $sheet->getCell('A1')->getValue());
        $this->assertSame('Código', $sheet->getCell('A2')->getValue());
        $this->assertSame('ARR-01', $sheet->getCell('A3')->getValue());
        $this->assertSame('5 cj', $sheet->getCell('I3')->getValue());
        $this->assertSame('FRI-01', $sheet->getCell('A4')->getValue());
        $this->assertSame('TOTAL', $sheet->getCell('A5')->getValue());
        $this->assertEqualsWithDelta(1680.00, (float) $sheet->getCell('N5')->getValue(), 0.001);

        $this->assertSame(1, $this->admin->notifications()->count());
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function expectedExportPath(): string
    {
        return 'exports/ventas_por_producto_'.now()->startOfMonth()->toDateString()
            .'_al_'.now()->toDateString().'_'.now()->format('His').'.xlsx';
    }

    private function sale(Warehouse $warehouse, string $productId, string $description, int $units, float $total): Invoice
    {
        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouse->id, 'date' => now()->toDateString()]);

        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => now()->toDateString(),
            'total' => $total,
        ]);

        InvoiceLine::factory()->for($invoice, 'invoice')->create([
            'product_id' => $productId,
            'product_description' => $description,
            'unit_sale' => 'UN',
            'quantity_box' => 0,
            'quantity_fractions' => $units,
            'conversion_factor' => 24,
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
        ]);

        return $invoice;
    }

    /**
     * @param  list<int>  $warehouseIds
     */
    private function printUrlFor(array $warehouseIds): string
    {
        return route('reports.product-sales', [
            'payload' => Crypt::encryptString((string) json_encode([
                'date_from' => now()->startOfMonth()->toDateString(),
                'date_to' => now()->toDateString(),
                'date_field' => 'invoice_date',
                'warehouse_ids' => $warehouseIds,
                'sort' => ProductSalesReportService::SORT_CODE,
            ])),
        ]);
    }
}
