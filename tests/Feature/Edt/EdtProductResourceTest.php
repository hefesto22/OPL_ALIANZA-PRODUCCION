<?php

namespace Tests\Feature\Edt;

use App\Enums\Edt\PriceChangeReason;
use App\Filament\Resources\Edt\Products\EdtProductResource;
use App\Filament\Resources\Edt\Products\Pages\CreateEdtProduct;
use App\Filament\Resources\Edt\Products\Pages\EditEdtProduct;
use App\Filament\Resources\Edt\Products\Pages\ListEdtProducts;
use App\Filament\Resources\Edt\Products\RelationManagers\PriceHistoryRelationManager;
use App\Filament\Resources\Edt\Products\Support\EdtPricePreview;
use App\Filament\Resources\Edt\Products\Support\PriceSnapshot;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtSupplier;
use App\Models\User;
use Database\Seeders\EdtPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pantallas de Productos del EDT: listado con precios calculados, alta,
 * edición sin tocar precios y la acción «Cambiar precio» con su permiso.
 */
class EdtProductResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private EdtSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'encargado', 'operador'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        $this->seed(EdtPermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->supplier = EdtSupplier::factory()->create(['code' => 'EDT', 'name' => 'EDT HONDURAS']);

        Filament::setCurrentPanel('admin');
    }

    // ── Listado ─────────────────────────────────────────────────────

    public function test_el_listado_muestra_los_precios_calculados_y_las_escalas(): void
    {
        $this->gallo();

        $this->actingAs($this->admin)
            ->get(EdtProductResource::getUrl('index'))
            ->assertOk()
            ->assertSee('CR GALLO LATA 350ML CT')
            ->assertSee('28000052')            // código, debajo de la descripción
            ->assertSee('L 422.40')            // detalle por caja
            ->assertSee('L 17.60 c/u')         // detalle por unidad, debajo de la caja
            ->assertSee('L 359.04')            // costo por caja: 17.60 − 15%, × 24
            ->assertSee('L 14.96 c/u')         // costo por unidad, debajo
            ->assertSee('L 410.00')            // MAYORISTA 1
            ->assertSee('Mayorista por caja')
            ->assertSee('50+ cajas');

        $this->assertSame('/edt/productos', parse_url(EdtProductResource::getUrl('index'), PHP_URL_PATH));
    }

    public function test_un_usuario_sin_permisos_del_edt_recibe_403(): void
    {
        // operador: entra al panel pero no tiene permisos del EDT.
        $operador = User::factory()->create(['is_active' => true]);
        $operador->assignRole('operador');

        $this->actingAs($operador)
            ->get(EdtProductResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_el_buscador_encuentra_por_codigo_y_por_descripcion(): void
    {
        $gallo = $this->gallo();
        $otro = EdtProduct::factory()->for($this->supplier, 'supplier')->create([
            'code' => '27000266',
            'description' => 'INCAPARINA ATOL',
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->searchTable('28000052')
            ->assertCanSeeTableRecords([$gallo])
            ->assertCanNotSeeTableRecords([$otro])
            ->searchTable('incaparina')
            ->assertCanSeeTableRecords([$otro])
            ->assertCanNotSeeTableRecords([$gallo]);
    }

    public function test_un_producto_inactivo_lo_dice_en_la_fila(): void
    {
        $this->gallo()->update(['is_active' => false, 'category' => 'BEBIDAS', 'presentation' => null]);

        $this->actingAs($this->admin)
            ->get(EdtProductResource::getUrl('index'))
            ->assertOk()
            ->assertSee('28000052 · BEBIDAS · INACTIVO');
    }

    public function test_ordenar_por_costo_por_caja(): void
    {
        $caro = EdtProduct::factory()->for($this->supplier, 'supplier')->create(['list_price' => '10.00', 'box_cost' => '900']);
        $barato = EdtProduct::factory()->for($this->supplier, 'supplier')->create(['list_price' => '50.00', 'box_cost' => '100']);

        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->sortTable('box_cost')
            ->assertCanSeeTableRecords([$barato, $caro], inOrder: true)
            ->sortTable('box_cost', 'desc')
            ->assertCanSeeTableRecords([$caro, $barato], inOrder: true);
    }

    public function test_el_listado_filtra_por_isv(): void
    {
        $gallo = $this->gallo();
        $gravado = EdtProduct::factory()->for($this->supplier, 'supplier')->create(['isv_pct' => 15]);

        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->filterTable('isv_pct', '0')
            ->assertCanSeeTableRecords([$gallo])
            ->assertCanNotSeeTableRecords([$gravado]);
    }

    // ── Alta ────────────────────────────────────────────────────────

    public function test_con_un_solo_proveedor_viene_elegido(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtProduct::class)
            ->assertFormSet(['supplier_id' => $this->supplier->id, 'is_active' => true]);
    }

    public function test_el_admin_crea_un_producto_y_queda_su_primera_fila_de_historial(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtProduct::class)
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'code' => '27000266',
                'description' => 'Incaparina atol c/amino orgnl 450g',
                'category' => 'alimentos',
                'price_includes_isv' => 0,
                'price' => '29.20',
                'units_per_box' => 50,
                'isv_pct' => '15',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = EdtProduct::query()->where('code', '27000266')->sole();
        $this->assertSame('29.2000', $product->list_price);
        $this->assertSame('INCAPARINA ATOL C/AMINO ORGNL 450G', $product->description);
        $this->assertSame('15.00', $product->isv_pct);

        $history = $product->priceHistory()->sole();
        $this->assertSame(PriceChangeReason::Creacion, $history->reason);
        // Fila 3 del Excel: 33.58 / 1,679.00 / 1,629 / 1,579.
        $this->assertSame('33.58', $history->retail_unit_price);
        $this->assertSame('1679.00', $history->retail_box_price);
        $this->assertSame($this->admin->id, $history->created_by);
    }

    public function test_el_alta_con_precio_con_isv_calcula_la_lista_sin_isv(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtProduct::class)
            ->assertFormSet(['price_includes_isv' => 1])
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'code' => '27000300',
                'description' => 'Producto de prueba',
                'price' => '30',
                'units_per_box' => 24,
                'isv_pct' => '15',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = EdtProduct::query()->where('code', '27000300')->sole();
        // 30 ÷ 1.15 = 26.0869565… → 26.0870; el detalle vuelve a ser 30.00 exacto.
        $this->assertSame('26.0870', $product->list_price);
        $this->assertSame('30.00', $product->priceQuote()->retailUnitPrice);
        $this->assertSame('720.00', $product->priceQuote()->retailBoxPrice);
        $this->assertSame('30.00', $product->priceHistory()->sole()->retail_unit_price);
    }

    public function test_valida_los_datos_del_producto(): void
    {
        $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtProduct::class)
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'code' => ' 28000052 ',     // repetido, con espacios
                'description' => 'X',
                'price' => '0',
                'units_per_box' => 0,
                'isv_pct' => '12',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'code' => 'unique',
                'price',
                'units_per_box',
                'isv_pct',
            ]);

        $this->assertSame(1, EdtProduct::query()->count());
    }

    public function test_sin_isv_acepta_4_decimales_y_con_isv_solo_centavos(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        $create = fn (array $price) => Livewire::test(CreateEdtProduct::class)
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'code' => '99999999',
                'description' => 'X',
                'units_per_box' => 25,
                'isv_pct' => '15',
                ...$price,
            ])
            ->call('create');

        $create(['price_includes_isv' => 0, 'price' => '8.69565'])->assertHasFormErrors(['price' => 'decimal']);
        $create(['price_includes_isv' => 1, 'price' => '10.005'])->assertHasFormErrors(['price' => 'decimal']);
        $create(['price_includes_isv' => 0, 'price' => '8.6957'])->assertHasNoFormErrors();

        Livewire::test(ListEdtProducts::class)
            ->callTableAction('changePrice', $gallo, data: [
                'price_includes_isv' => 0,
                'price' => '8.69565',
                'units_per_box' => 24,
                'isv_pct' => '0',
                'reason' => PriceChangeReason::Correccion->value,
            ])
            ->assertHasTableActionErrors(['price' => 'decimal']);
    }

    // ── Edición ─────────────────────────────────────────────────────

    public function test_editar_cambia_datos_descriptivos_sin_tocar_precio_ni_historial(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(EditEdtProduct::class, ['record' => $gallo->getRouteKey()])
            ->fillForm([
                'description' => 'cr gallo lata 350ml',
                // Bloqueados en edición: aunque lleguen, no se guardan.
                'list_price' => '99.00',
                'box_cost' => '1.00',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $gallo->refresh();
        $this->assertSame('CR GALLO LATA 350ML', $gallo->description);
        $this->assertSame('17.6000', $gallo->list_price);
        $this->assertSame('359.0400', $gallo->box_cost);
        $this->assertSame(24, $gallo->units_per_box);
        $this->assertSame('0.00', $gallo->isv_pct);
        $this->assertSame(1, $gallo->priceHistory()->count());
    }

    public function test_editar_el_isv_mantiene_lo_que_cuesta_y_vale_sin_isv(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(EditEdtProduct::class, ['record' => $gallo->getRouteKey()])
            ->fillForm(['isv_pct' => '15'])
            ->assertSee('Así quedarían los precios')
            ->assertSee('ISV EXENTO → 15%')
            ->call('save')
            ->assertHasNoFormErrors();

        $gallo->refresh();
        $this->assertSame('15.00', $gallo->isv_pct);
        // Sin ISV, el precio y el costo por unidad son los mismos; la caja suma el ISV.
        $this->assertSame('17.6000', $gallo->list_price);
        $this->assertSame('412.8960', $gallo->box_cost);
        $this->assertSame('20.24', $gallo->priceQuote()->retailUnitPrice);

        $last = $gallo->priceHistory()->latest('id')->first();
        $this->assertSame(PriceChangeReason::Edicion, $last->reason);
        $this->assertSame('412.8960', $last->box_cost);
    }

    public function test_editar_las_unidades_por_caja_recalcula_el_costo_por_caja(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(EditEdtProduct::class, ['record' => $gallo->getRouteKey()])
            ->fillForm(['units_per_box' => 12])
            ->call('save')
            ->assertHasNoFormErrors();

        $gallo->refresh();
        $this->assertSame(12, $gallo->units_per_box);
        $this->assertSame('179.5200', $gallo->box_cost);
        $this->assertSame('14.9600', $gallo->priceQuote()->unitCost);
        $this->assertSame(2, $gallo->priceHistory()->count());
    }

    public function test_cambiar_precio_no_cambia_el_isv_ni_las_unidades(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->callTableAction('changePrice', $gallo, data: [
                'price' => '21.28',
                // Aunque lleguen, en «Cambiar precio» no se guardan.
                'units_per_box' => 1,
                'isv_pct' => '18',
                'reason' => PriceChangeReason::ListaProveedor->value,
            ])
            ->assertHasNoTableActionErrors();

        $gallo->refresh();
        $this->assertSame('21.2800', $gallo->list_price);
        $this->assertSame(24, $gallo->units_per_box);
        $this->assertSame('0.00', $gallo->isv_pct);
    }

    public function test_el_historial_se_ve_en_la_pantalla_de_edicion(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(PriceHistoryRelationManager::class, [
            'ownerRecord' => $gallo,
            'pageClass' => EditEdtProduct::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords($gallo->priceHistory()->get());
    }

    // ── Cambiar precio ──────────────────────────────────────────────

    public function test_cambiar_precio_desde_la_tabla_escribe_historial_con_motivo(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->callTableAction('changePrice', $gallo, data: [
                'price' => '21.28',
                'units_per_box' => 24,
                'isv_pct' => '0',
                'reason' => PriceChangeReason::ListaProveedor->value,
                'note' => 'lista octubre',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('21.2800', $gallo->fresh()->list_price);

        $last = $gallo->priceHistory()->latest('id')->first();
        $this->assertSame(PriceChangeReason::ListaProveedor, $last->reason);
        $this->assertSame('LISTA OCTUBRE', $last->note);
        $this->assertSame('496.00', $last->wholesale_prices[0]['box_price']);
    }

    public function test_cambiar_precio_desde_la_pantalla_de_edicion(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(EditEdtProduct::class, ['record' => $gallo->getRouteKey()])
            ->callAction('changePrice', data: [
                'price' => '21.28',
                'units_per_box' => 24,
                'isv_pct' => '0',
                'reason' => PriceChangeReason::FacturaCompra->value,
            ])
            ->assertHasNoActionErrors()
            ->assertFormSet(['list_price' => '21.28']);

        $this->assertSame(2, $gallo->priceHistory()->count());
    }

    public function test_cambiar_precio_arranca_con_el_precio_con_isv_vigente(): void
    {
        $product = EdtProduct::factory()->for($this->supplier, 'supplier')->create([
            'list_price' => '20.00',
            'units_per_box' => 12,
            'isv_pct' => 15,
        ]);
        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->mountTableAction('changePrice', $product)
            ->assertTableActionDataSet([
                'price_includes_isv' => 1,
                'price' => '23.00',
            ])
            ->setTableActionData(['price' => '30', 'reason' => PriceChangeReason::ListaProveedor->value])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('26.0870', $product->fresh()->list_price);
        $this->assertSame('30.00', $product->fresh()->priceQuote()->retailUnitPrice);
    }

    public function test_guardar_sin_cambiar_el_precio_con_isv_no_mueve_la_lista(): void
    {
        // 17.86 × 1.15 = 20.539 → 20.54; 20.54 ÷ 1.15 = 17.8609. Ir y volver
        // cambiaría la lista: abrir y guardar sin tocar no debe registrar nada.
        $product = EdtProduct::factory()->for($this->supplier, 'supplier')->create([
            'list_price' => '17.86',
            'units_per_box' => 12,
            'isv_pct' => 15,
        ]);
        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->callTableAction('changePrice', $product, data: ['reason' => PriceChangeReason::ListaProveedor->value])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Sin cambios');

        $this->assertSame('17.8600', $product->fresh()->list_price);
        $this->assertSame(1, $product->priceHistory()->count());
    }

    public function test_cambiar_precio_muestra_antes_despues_y_diferencia(): void
    {
        $product = EdtProduct::factory()->for($this->supplier, 'supplier')->create([
            'list_price' => '20.00',
            'units_per_box' => 12,
            'isv_pct' => 15,
        ]);
        $this->actingAs($this->admin);

        $before = PriceSnapshot::fromProduct($product);
        $new = fn (string $list, string $isv = '15', ?string $cost = null): ?PriceSnapshot => PriceSnapshot::fromInput($list, 12, $isv, $cost ?? (string) $product->box_cost);

        $this->assertStringContainsString('Sin cambios', (string) EdtPricePreview::render($new('20.00'), $before));

        $html = (string) EdtPricePreview::render($new('26.0870'), $before);
        $this->assertStringContainsString('L 30.00', $html);
        $this->assertStringContainsString('+L 7.00 · +30.43%', $html);   // 23.00 → 30.00
        $this->assertStringContainsString('L 26.0870', $html);           // la lista sin ISV que se guarda
        $this->assertStringContainsString('(antes L 20.00)', $html);
        $this->assertStringContainsString('Desde 25 cajas · 3% de descuento', $html);
        $this->assertStringContainsString('Diferencia', $html);
        $this->assertStringNotContainsString('Sin cambios', $html);

        $this->assertStringContainsString('ISV 15% → 18%', (string) EdtPricePreview::render($new('20.00', '18'), $before));

        // Costo por caja: 20 − 15% + ISV = 19.55 × 12 = 234.60; si sube a 250, se ve la diferencia.
        $html = (string) EdtPricePreview::render($new('20.00', '15', '250'), $before);
        $this->assertStringContainsString('Costo por caja', $html);
        $this->assertStringContainsString('L 234.60', $html);
        $this->assertStringContainsString('+L 15.40', $html);
        $this->assertStringContainsString('aquí ves cómo quedan todos los precios', (string) EdtPricePreview::render(null));

        // Y el modal abre con ella.
        Livewire::test(EditEdtProduct::class, ['record' => $product->getRouteKey()])
            ->mountAction('changePrice')
            ->assertActionMounted('changePrice');
    }

    public function test_pasar_a_sin_isv_convierte_lo_escrito_sin_cambiar_el_precio(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtProduct::class)
            ->assertSee('aquí ves cómo quedan todos los precios')
            ->fillForm(['isv_pct' => '15', 'units_per_box' => 24, 'price' => '30'])
            ->fillForm(['price_includes_isv' => 0])
            ->assertFormSet(['price' => '26.087'])
            ->assertSee('L 30.00')
            ->fillForm(['price_includes_isv' => 1])
            ->assertFormSet(['price' => '30.00']);
    }

    public function test_la_edicion_muestra_los_precios_vigentes(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(EditEdtProduct::class, ['record' => $gallo->getRouteKey()])
            ->assertSee('Precios vigentes')
            ->assertSee('L 422.40')
            ->assertSee('Se guarda la lista sin ISV')
            ->assertDontSee('Diferencia');
    }

    public function test_el_historial_de_precios_se_abre_desde_el_listado(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->callTableAction('changePrice', $gallo, data: [
                'price' => '21.28',
                'units_per_box' => 24,
                'isv_pct' => '0',
                'reason' => PriceChangeReason::ListaProveedor->value,
            ])
            ->assertTableActionVisible('priceHistory', $gallo)
            ->mountTableAction('priceHistory', $gallo)
            ->assertOk();

        // El contenido del modal: la tabla del historial, la más nueva arriba.
        $html = view('filament.edt.price-history-modal', ['record' => $gallo])->render();
        $this->assertStringContainsString('Detalle unidad', $html);
        $this->assertStringContainsString('L 21.28', $html);
        $this->assertStringContainsString('L 17.60', $html);
        $this->assertStringContainsString('Costo unidad', $html);
    }

    public function test_cambiar_precio_arranca_con_el_costo_vigente_y_lo_guarda(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->mountTableAction('changePrice', $gallo)
            ->assertTableActionDataSet(['price' => '17.60', 'cost' => '359.04'])
            ->setTableActionData(['cost' => '336', 'reason' => PriceChangeReason::FacturaCompra->value])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $gallo->refresh();
        $this->assertSame('336.0000', $gallo->box_cost);
        $this->assertSame('17.6000', $gallo->list_price, 'Solo cambió el costo; el precio de venta queda igual.');
        $this->assertSame('14.0000', $gallo->priceQuote()->unitCost);
        $this->assertSame('336.0000', $gallo->priceHistory()->latest('id')->first()->box_cost);
    }

    public function test_el_costo_sin_isv_se_guarda_con_isv(): void
    {
        $product = EdtProduct::factory()->for($this->supplier, 'supplier')->create([
            'list_price' => '20.00', 'units_per_box' => 12, 'isv_pct' => 15,
        ]);
        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->mountTableAction('changePrice', $product)
            ->setTableActionData(['price_includes_isv' => 0])
            ->assertTableActionDataSet(['price' => '20.00', 'cost' => '204.00']) // 234.60 ÷ 1.15
            ->setTableActionData(['cost' => '200', 'reason' => PriceChangeReason::FacturaCompra->value])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('230.0000', $product->fresh()->box_cost); // 200 × 1.15
        $this->assertSame('20.0000', $product->fresh()->list_price);
    }

    public function test_al_crear_el_costo_se_sugiere_con_el_descuento_del_proveedor(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtProduct::class)
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'code' => '27000301',
                'description' => 'Producto con costo sugerido',
                'units_per_box' => 24,
                'isv_pct' => '15',
                'price' => '30',
            ])
            // 30 ÷ 1.15 = 26.087 − 15% + 15% ISV = 25.50 × 24.
            ->assertFormSet(['cost' => '612.00'])
            ->fillForm(['cost' => '600'])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = EdtProduct::query()->where('code', '27000301')->sole();
        $this->assertSame('600.0000', $product->box_cost);
        $this->assertSame('25.0000', $product->priceQuote()->unitCost);
        $this->assertSame('30.00', $product->priceQuote()->retailUnitPrice);
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->callTableAction('changePrice', $gallo, data: [
                'price' => '21.28',
                'units_per_box' => 24,
                'isv_pct' => '0',
                'reason' => null,
            ])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame(1, $gallo->priceHistory()->count());
    }

    public function test_sin_permiso_changeprice_no_ve_la_accion_aunque_pueda_editar(): void
    {
        $gallo = $this->gallo();

        $editor = User::factory()->create(['is_active' => true]);
        $editor->givePermissionTo(Permission::findByName('ViewAny:EdtProduct'));
        $editor->givePermissionTo(Permission::findByName('View:EdtProduct'));
        $editor->givePermissionTo(Permission::findByName('Update:EdtProduct'));
        $editor->assignRole('encargado'); // para entrar al panel

        $this->actingAs($editor);

        Livewire::test(ListEdtProducts::class)
            ->assertTableActionHidden('changePrice', $gallo)
            ->assertTableActionVisible('edit', $gallo);
    }

    private function gallo(): EdtProduct
    {
        return EdtProduct::factory()->for($this->supplier, 'supplier')->create([
            'code' => '28000052',
            'description' => 'CR GALLO LATA 350ML CT',
            'units_per_box' => 24,
            'isv_pct' => 0,
            'list_price' => '17.60',
        ]);
    }
}
