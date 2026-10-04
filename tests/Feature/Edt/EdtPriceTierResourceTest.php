<?php

namespace Tests\Feature\Edt;

use App\Enums\Edt\PriceChangeReason;
use App\Filament\Resources\Edt\PriceTiers\EdtPriceTierResource;
use App\Filament\Resources\Edt\PriceTiers\Pages\ManageEdtPriceTiers;
use App\Filament\Resources\Edt\Suppliers\Pages\EditEdtSupplier;
use App\Models\Edt\EdtPriceTier;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtSupplier;
use App\Models\User;
use Database\Seeders\EdtPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pantalla de escalas mayoristas (configurables) y el bloqueo de borrado de
 * proveedores con productos.
 */
class EdtPriceTierResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        $this->seed(EdtPermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel('admin');
    }

    public function test_arranca_con_las_dos_escalas_del_excel(): void
    {
        $this->assertSame(
            [['MAYORISTA 1', 25, '3.00'], ['MAYORISTA 2', 50, '6.00']],
            EdtPriceTier::query()->orderBy('min_boxes')->get()
                ->map(fn (EdtPriceTier $t): array => [$t->name, $t->min_boxes, $t->discount_pct])
                ->all(),
        );
    }

    public function test_el_admin_ve_las_escalas(): void
    {
        $this->actingAs($this->admin)
            ->get(EdtPriceTierResource::getUrl('index'))
            ->assertOk()
            ->assertSee('MAYORISTA 1')
            ->assertSee('MAYORISTA 2');
    }

    public function test_crear_una_escala_recalcula_y_queda_en_el_historial(): void
    {
        $product = EdtProduct::factory()->create();
        $this->actingAs($this->admin);

        Livewire::test(ManageEdtPriceTiers::class)
            ->callAction('create', data: [
                'name' => 'mayorista 3',
                'min_boxes' => 100,
                'discount_pct' => 8,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('edt_price_tiers', ['name' => 'MAYORISTA 3', 'min_boxes' => 100]);
        $this->assertSame(PriceChangeReason::Escalas, $product->priceHistory()->latest('id')->first()->reason);
    }

    public function test_no_deja_dos_escalas_con_la_misma_cantidad_de_cajas(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageEdtPriceTiers::class)
            ->callAction('create', data: [
                'name' => 'OTRA',
                'min_boxes' => 25,
                'discount_pct' => 4,
            ])
            ->assertHasActionErrors(['min_boxes' => 'unique']);
    }

    public function test_editar_el_porcentaje_desde_la_pantalla(): void
    {
        $product = EdtProduct::factory()->create();
        $tier = EdtPriceTier::query()->where('name', 'MAYORISTA 1')->sole();
        $this->actingAs($this->admin);

        Livewire::test(ManageEdtPriceTiers::class)
            ->callTableAction('edit', $tier, data: ['discount_pct' => 2])
            ->assertHasNoTableActionErrors();

        $this->assertSame('2.00', $tier->fresh()->discount_pct);
        $this->assertSame(2, $product->priceHistory()->count());
    }

    public function test_un_proveedor_con_productos_no_muestra_borrar(): void
    {
        $supplier = EdtSupplier::factory()->create();
        EdtProduct::factory()->for($supplier, 'supplier')->create();
        $this->actingAs($this->admin);

        $this->assertFalse($this->admin->can('delete', $supplier));

        Livewire::test(EditEdtSupplier::class, ['record' => $supplier->getRouteKey()])
            ->assertActionHidden('delete');
    }

    public function test_un_proveedor_sin_productos_si_se_puede_borrar(): void
    {
        $supplier = EdtSupplier::factory()->create();

        $this->assertTrue($this->admin->can('delete', $supplier));
    }
}
