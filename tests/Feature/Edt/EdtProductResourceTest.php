<?php

namespace Tests\Feature\Edt;

use App\Enums\Edt\PriceChangeReason;
use App\Filament\Resources\Edt\Products\EdtProductResource;
use App\Filament\Resources\Edt\Products\Pages\CreateEdtProduct;
use App\Filament\Resources\Edt\Products\Pages\EditEdtProduct;
use App\Filament\Resources\Edt\Products\Pages\ListEdtProducts;
use App\Filament\Resources\Edt\Products\RelationManagers\PriceHistoryRelationManager;
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

        foreach (['super_admin', 'admin', 'encargado'] as $role) {
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
            ->assertSee('L 422.40')            // detalle por caja
            ->assertSee('L 410.00')            // MAYORISTA 1
            ->assertSee('MAYORISTA 2 (50+ CJ)');

        $this->assertSame('/edt/productos', parse_url(EdtProductResource::getUrl('index'), PHP_URL_PATH));
    }

    public function test_un_usuario_sin_permisos_del_edt_recibe_403(): void
    {
        $encargado = User::factory()->create(['is_active' => true]);
        $encargado->assignRole('encargado');

        $this->actingAs($encargado)
            ->get(EdtProductResource::getUrl('index'))
            ->assertForbidden();
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
                'list_price' => '29.20',
                'units_per_box' => 50,
                'isv_pct' => '15',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = EdtProduct::query()->where('code', '27000266')->sole();
        $this->assertSame('INCAPARINA ATOL C/AMINO ORGNL 450G', $product->description);
        $this->assertSame('15.00', $product->isv_pct);

        $history = $product->priceHistory()->sole();
        $this->assertSame(PriceChangeReason::Creacion, $history->reason);
        // Fila 3 del Excel: 33.58 / 1,679.00 / 1,629 / 1,579.
        $this->assertSame('33.58', $history->retail_unit_price);
        $this->assertSame('1679.00', $history->retail_box_price);
        $this->assertSame($this->admin->id, $history->created_by);
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
                'list_price' => '0',
                'units_per_box' => 0,
                'isv_pct' => '12',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'code' => 'unique',
                'list_price',
                'units_per_box',
                'isv_pct',
            ]);

        $this->assertSame(1, EdtProduct::query()->count());
    }

    public function test_el_precio_de_lista_acepta_maximo_4_decimales(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtProduct::class)
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'code' => '99999999',
                'description' => 'X',
                'list_price' => '8.69565',
                'units_per_box' => 25,
                'isv_pct' => '15',
            ])
            ->call('create')
            ->assertHasFormErrors(['list_price' => 'decimal']);

        Livewire::test(ListEdtProducts::class)
            ->callTableAction('changePrice', $gallo, data: [
                'list_price' => '8.69565',
                'units_per_box' => 24,
                'isv_pct' => '0',
                'reason' => PriceChangeReason::Correccion->value,
            ])
            ->assertHasTableActionErrors(['list_price' => 'decimal']);
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
                'units_per_box' => 1,
                'isv_pct' => '15',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $gallo->refresh();
        $this->assertSame('CR GALLO LATA 350ML', $gallo->description);
        $this->assertSame('17.6000', $gallo->list_price);
        $this->assertSame(24, $gallo->units_per_box);
        $this->assertSame('0.00', $gallo->isv_pct);
        $this->assertSame(1, $gallo->priceHistory()->count());
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
                'list_price' => '21.28',
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
                'list_price' => '21.28',
                'units_per_box' => 24,
                'isv_pct' => '0',
                'reason' => PriceChangeReason::FacturaCompra->value,
            ])
            ->assertHasNoActionErrors()
            ->assertFormSet(['list_price' => '21.2800']);

        $this->assertSame(2, $gallo->priceHistory()->count());
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $gallo = $this->gallo();
        $this->actingAs($this->admin);

        Livewire::test(ListEdtProducts::class)
            ->callTableAction('changePrice', $gallo, data: [
                'list_price' => '21.28',
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
