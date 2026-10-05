<?php

namespace Tests\Feature\Edt;

use App\Filament\Resources\Edt\Sellers\EdtSellerResource;
use App\Filament\Resources\Edt\Sellers\Pages\CreateEdtSeller;
use App\Filament\Resources\Edt\Sellers\Pages\EditEdtSeller;
use App\Filament\Resources\Edt\Sellers\Pages\ListEdtSellers;
use App\Models\Edt\EdtRoute;
use App\Models\Edt\EdtSeller;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Edt\EdtRouteAssignmentService;
use App\Services\Edt\EdtRuleException;
use App\Services\Edt\EdtSellerService;
use App\Support\Edt\EdtModule;
use Database\Seeders\EdtPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Vendedores: el alta crea también su usuario (rol vendedor_edt, su bodega),
 * los cambios se copian al usuario, y el encargado solo maneja los de su
 * bodega.
 */
class EdtSellerResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $encargadoOac;

    private Warehouse $oac;

    private Warehouse $oas;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'encargado', 'operador'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        $this->seed(EdtPermissionSeeder::class);

        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->encargadoOac = User::factory()->forWarehouse($this->oac)->create(['is_active' => true]);
        $this->encargadoOac->assignRole('encargado');

        Filament::setCurrentPanel('admin');
    }

    // ── Alta ────────────────────────────────────────────────────────

    public function test_el_alta_crea_el_vendedor_y_su_usuario(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtSeller::class)
            ->fillForm($this->validData(['name' => 'juan pérez', 'email' => ' Juan.Perez@Hozana.TEST ']))
            ->call('create')
            ->assertHasNoFormErrors();

        $seller = EdtSeller::query()->sole();
        $user = $seller->user;

        $this->assertSame('JUAN PÉREZ', $seller->name);
        $this->assertSame('V-01', $seller->code);
        $this->assertSame('JUAN PÉREZ', $user->name);
        $this->assertSame('juan.perez@hozana.test', $user->email);
        $this->assertTrue(Hash::check('secreto123', $user->password));
        $this->assertTrue($user->hasRole(EdtModule::SELLER_ROLE));
        $this->assertSame([$this->oac->id], $user->warehouseIds());
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertSame($this->admin->id, $seller->created_by);
    }

    public function test_valida_codigo_correo_unicos_y_contrasena(): void
    {
        User::factory()->create(['email' => 'ocupado@hozana.test']);
        EdtSeller::factory()->create(['code' => 'V-01', 'warehouse_id' => $this->oac->id]);
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtSeller::class)
            ->fillForm($this->validData(['code' => 'v-01', 'email' => 'OCUPADO@hozana.test', 'password' => '123']))
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique', 'email' => 'unique', 'password' => 'min']);

        $this->assertSame(1, EdtSeller::query()->count());
    }

    public function test_el_encargado_crea_vendedores_solo_en_su_bodega(): void
    {
        $this->actingAs($this->encargadoOac);

        Livewire::test(CreateEdtSeller::class)
            ->assertFormSet(['warehouse_id' => $this->oac->id])
            ->fillForm($this->validData(['warehouse_id' => $this->oas->id]))
            ->call('create')
            ->assertHasFormErrors(['warehouse_id']);

        $this->assertSame(0, EdtSeller::query()->count());
    }

    // ── Listado y acceso ────────────────────────────────────────────

    public function test_el_encargado_solo_ve_los_vendedores_de_su_bodega(): void
    {
        $own = EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]);
        $other = EdtSeller::factory()->create(['warehouse_id' => $this->oas->id]);

        $this->actingAs($this->encargadoOac);

        Livewire::test(ListEdtSellers::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);

        $this->get(EdtSellerResource::getUrl('edit', ['record' => $other]))->assertNotFound();
        $this->assertFalse($this->encargadoOac->can('update', $other));
    }

    public function test_el_listado_muestra_las_rutas_vigentes(): void
    {
        $seller = EdtSeller::factory()->create(['warehouse_id' => $this->oac->id, 'name' => 'JUAN']);
        $route = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id, 'name' => 'RUTA LUNES']);
        app(EdtRouteAssignmentService::class)->assign($route, $seller, today());

        $this->actingAs($this->admin)
            ->get(EdtSellerResource::getUrl('index'))
            ->assertOk()
            ->assertSee('JUAN')
            ->assertSee('RUTA LUNES');
    }

    public function test_un_operador_no_ve_vendedores(): void
    {
        $operador = User::factory()->forWarehouse($this->oac)->create(['is_active' => true]);
        $operador->assignRole('operador');

        $this->actingAs($operador)
            ->get(EdtSellerResource::getUrl('index'))
            ->assertForbidden();
    }

    // ── Edición ─────────────────────────────────────────────────────

    public function test_editar_copia_los_cambios_al_usuario_y_la_contrasena_es_opcional(): void
    {
        $seller = app(EdtSellerService::class)->create($this->validData());
        $oldHash = $seller->user->password;
        $this->actingAs($this->admin);

        Livewire::test(EditEdtSeller::class, ['record' => $seller->getRouteKey()])
            ->assertFormSet(['email' => 'vendedor@hozana.test'])
            ->fillForm(['name' => 'pedro gómez', 'email' => 'pedro@hozana.test', 'password' => null, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $user = $seller->fresh()->user;
        $this->assertSame('PEDRO GÓMEZ', $user->name);
        $this->assertSame('pedro@hozana.test', $user->email);
        $this->assertSame($oldHash, $user->password);
        // Inactivo: ya no entra.
        $this->assertFalse($user->is_active);
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));

        Livewire::test(EditEdtSeller::class, ['record' => $seller->getRouteKey()])
            ->fillForm(['password' => 'nuevaClave9'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('nuevaClave9', $seller->fresh()->user->password));
    }

    public function test_no_cambia_de_bodega_un_vendedor_que_ya_tuvo_rutas(): void
    {
        $seller = app(EdtSellerService::class)->create($this->validData());
        $route = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id]);
        app(EdtRouteAssignmentService::class)->assign($route, $seller, Carbon::parse('2026-09-01'));

        $this->expectException(EdtRuleException::class);

        app(EdtSellerService::class)->update($seller, ['warehouse_id' => $this->oas->id]);
    }

    public function test_sin_rutas_si_puede_cambiar_de_bodega_y_su_usuario_lo_sigue(): void
    {
        $seller = app(EdtSellerService::class)->create($this->validData());

        app(EdtSellerService::class)->update($seller, ['warehouse_id' => $this->oas->id]);

        $this->assertSame($this->oas->id, $seller->fresh()->warehouse_id);
        $this->assertSame([$this->oas->id], $seller->fresh()->user->warehouseIds());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'code' => 'v-01',
            'name' => 'VENDEDOR DE PRUEBA',
            'phone' => '9999-9999',
            'warehouse_id' => $this->oac->id,
            'email' => 'vendedor@hozana.test',
            'password' => 'secreto123',
            'is_active' => true,
        ], $overrides);
    }
}
