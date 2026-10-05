<?php

namespace Tests\Feature\Edt;

use App\Filament\Resources\Edt\Routes\EdtRouteResource;
use App\Filament\Resources\Edt\Routes\Pages\CreateEdtRoute;
use App\Filament\Resources\Edt\Routes\Pages\EditEdtRoute;
use App\Filament\Resources\Edt\Routes\Pages\ListEdtRoutes;
use App\Filament\Resources\Edt\Routes\RelationManagers\AssignmentsRelationManager;
use App\Models\Edt\EdtRoute;
use App\Models\Edt\EdtSeller;
use App\Models\Geo\Municipality;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Edt\EdtRouteAssignmentService;
use App\Support\Edt\EdtModule;
use Database\Seeders\EdtPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Rutas: alta con municipios y días, asignación de vendedor desde la tabla
 * y la página, historial, y aislamiento por bodega.
 */
class EdtRouteResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $encargadoOac;

    private Warehouse $oac;

    private Warehouse $oas;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 09:00:00');

        foreach (['super_admin', 'admin', 'encargado'] as $role) {
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Alta ────────────────────────────────────────────────────────

    public function test_crear_una_ruta_con_municipios_y_dias(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtRoute::class)
            ->fillForm([
                'name' => 'ruta lunes santa rosa',
                'warehouse_id' => $this->oac->id,
                'municipalities' => [$this->municipality('0401')->id, $this->municipality('0407')->id],
                'visit_days' => ['4', '1', '1'],
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $route = EdtRoute::query()->sole();
        $this->assertSame('RUTA LUNES SANTA ROSA', $route->name);
        $this->assertSame([1, 4], $route->visit_days);
        $this->assertSame('LUNES, JUEVES', $route->visitDaysLabel());
        $this->assertEqualsCanonicalizing(['0401', '0407'], $route->municipalities()->pluck('code')->all());

        $log = Activity::query()->where('description', 'Municipios de la ruta')->sole();
        $this->assertSame(EdtModule::LOG_NAME, $log->log_name);
        $this->assertSame(['DOLORES', 'SANTA ROSA DE COPÁN'], $log->properties['attributes']['municipios']);
    }

    public function test_sin_dias_marcados_es_cualquier_dia_y_el_nombre_es_unico_por_bodega(): void
    {
        EdtRoute::factory()->create(['warehouse_id' => $this->oac->id, 'name' => 'RUTA 1']);
        EdtRoute::factory()->create(['warehouse_id' => $this->oas->id, 'name' => 'RUTA 2']);
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtRoute::class)
            ->fillForm(['name' => 'ruta 1', 'warehouse_id' => $this->oac->id, 'municipalities' => [$this->municipality('0401')->id]])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);

        // En otra bodega el mismo nombre sí se puede.
        Livewire::test(CreateEdtRoute::class)
            ->fillForm(['name' => 'ruta 2', 'warehouse_id' => $this->oac->id, 'municipalities' => [$this->municipality('0401')->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $route = EdtRoute::query()->where('warehouse_id', $this->oac->id)->where('name', 'RUTA 2')->sole();
        $this->assertSame([], $route->visit_days);
        $this->assertSame('CUALQUIER DÍA', $route->visitDaysLabel());
    }

    public function test_cambiar_los_municipios_queda_en_la_bitacora_y_si_no_cambian_no(): void
    {
        $route = EdtRoute::factory()->covering(['0401'])->create(['warehouse_id' => $this->oac->id]);
        $this->actingAs($this->admin);

        Livewire::test(EditEdtRoute::class, ['record' => $route->getRouteKey()])
            ->fillForm(['name' => 'otro nombre'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, Activity::query()->where('description', 'Municipios de la ruta')->count());

        Livewire::test(EditEdtRoute::class, ['record' => $route->getRouteKey()])
            ->fillForm(['municipalities' => [$this->municipality('0401')->id, $this->municipality('0405')->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $log = Activity::query()->where('description', 'Municipios de la ruta')->sole();
        $this->assertSame(['SANTA ROSA DE COPÁN'], $log->properties['old']['municipios']);
        $this->assertSame(['CORQUÍN', 'SANTA ROSA DE COPÁN'], $log->properties['attributes']['municipios']);
        $this->assertSame($this->admin->id, $log->causer_id);
    }

    public function test_la_ruta_necesita_al_menos_un_municipio(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtRoute::class)
            ->fillForm(['name' => 'ruta vacía', 'warehouse_id' => $this->oac->id, 'municipalities' => []])
            ->call('create')
            ->assertHasFormErrors(['municipalities' => 'required']);
    }

    // ── Vendedor de la ruta ─────────────────────────────────────────

    public function test_asignar_vendedor_desde_la_tabla(): void
    {
        $route = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id]);
        $juan = EdtSeller::factory()->create(['warehouse_id' => $this->oac->id, 'name' => 'JUAN']);
        $this->actingAs($this->encargadoOac);

        Livewire::test(ListEdtRoutes::class)
            ->callTableAction('assignSeller', $route, data: ['seller_id' => $juan->id, 'starts_on' => '2026-10-05'])
            ->assertHasNoTableActionErrors()
            ->assertSee('JUAN');

        $this->assertSame($juan->id, $route->currentAssignment()->first()->seller_id);
    }

    public function test_la_lista_de_vendedores_solo_ofrece_activos_de_la_bodega_de_la_ruta(): void
    {
        $route = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id]);
        $other = EdtSeller::factory()->create(['warehouse_id' => $this->oas->id]);
        $inactive = EdtSeller::factory()->inactive()->create(['warehouse_id' => $this->oac->id]);
        $this->actingAs($this->admin);

        foreach ([$other, $inactive] as $seller) {
            Livewire::test(ListEdtRoutes::class)
                ->callTableAction('assignSeller', $route, data: ['seller_id' => $seller->id, 'starts_on' => '2026-10-05'])
                ->assertHasTableActionErrors(['seller_id']);
        }

        $this->assertSame(0, $route->assignments()->count());
    }

    public function test_una_fecha_que_se_cruza_avisa_y_no_guarda(): void
    {
        $route = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id]);
        app(EdtRouteAssignmentService::class)->assign($route, EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]), Carbon::parse('2026-10-05'));
        $pedro = EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]);
        $this->actingAs($this->admin);

        Livewire::test(EditEdtRoute::class, ['record' => $route->getRouteKey()])
            ->callAction('assignSeller', data: ['seller_id' => $pedro->id, 'starts_on' => '2026-10-01'])
            ->assertNotified('No se pudo');

        $this->assertSame(1, $route->assignments()->count());
    }

    public function test_quitar_y_cancelar_programacion_desde_la_pagina(): void
    {
        $route = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id]);
        $juan = EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]);
        $pedro = EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]);
        app(EdtRouteAssignmentService::class)->assign($route, $juan, Carbon::parse('2026-09-01'));
        $this->actingAs($this->admin);

        Livewire::test(EditEdtRoute::class, ['record' => $route->getRouteKey()])
            ->assertActionVisible('releaseSeller')
            ->assertActionHidden('cancelScheduled')
            ->callAction('assignSeller', data: ['seller_id' => $pedro->id, 'starts_on' => '2026-10-20']);

        // Hay una programación a futuro: se puede cancelar.
        Livewire::test(EditEdtRoute::class, ['record' => $route->getRouteKey()])
            ->assertActionVisible('cancelScheduled')
            ->callAction('cancelScheduled');

        $this->assertSame($juan->id, $route->currentAssignment()->first()->seller_id);
        $this->assertNull($route->assignments()->sole()->ends_on);

        Livewire::test(EditEdtRoute::class, ['record' => $route->getRouteKey()])
            ->callAction('releaseSeller', data: ['ends_on' => '2026-10-05']);

        $this->assertSame('2026-10-05', $route->assignments()->sole()->ends_on->toDateString());
    }

    public function test_el_historial_se_ve_en_la_ruta(): void
    {
        $route = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id]);
        app(EdtRouteAssignmentService::class)->assign($route, EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]), Carbon::parse('2026-09-01'));
        app(EdtRouteAssignmentService::class)->assign($route, EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]), Carbon::parse('2026-10-01'));
        $this->actingAs($this->admin);

        Livewire::test(AssignmentsRelationManager::class, ['ownerRecord' => $route, 'pageClass' => EditEdtRoute::class])
            ->assertOk()
            ->assertCanSeeTableRecords($route->assignments()->get())
            ->assertSee('TERMINADA')
            ->assertSee('VIGENTE');
    }

    // ── Permisos y bodegas ──────────────────────────────────────────

    public function test_el_encargado_solo_ve_y_maneja_rutas_de_su_bodega(): void
    {
        $own = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id]);
        $other = EdtRoute::factory()->create(['warehouse_id' => $this->oas->id]);
        $this->actingAs($this->encargadoOac);

        Livewire::test(ListEdtRoutes::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);

        $this->get(EdtRouteResource::getUrl('edit', ['record' => $other]))->assertNotFound();
        $this->assertFalse($this->encargadoOac->can('assignSeller', $other));
        $this->assertTrue($this->encargadoOac->can('assignSeller', $own));
        // Borrar es del admin.
        $this->assertFalse($this->encargadoOac->can('delete', $own));
    }

    public function test_sin_permiso_assign_seller_no_aparece_la_accion(): void
    {
        $route = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id]);
        $editor = User::factory()->forWarehouse($this->oac)->create(['is_active' => true]);
        $editor->assignRole('encargado');
        Role::findByName('encargado')->revokePermissionTo('AssignSeller:EdtRoute');
        $this->actingAs($editor->fresh());

        Livewire::test(ListEdtRoutes::class)
            ->assertTableActionHidden('assignSeller', $route)
            ->assertTableActionVisible('edit', $route);
    }

    public function test_solo_se_borra_una_ruta_que_nunca_tuvo_vendedor(): void
    {
        $route = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id]);

        $this->assertTrue($this->admin->can('delete', $route));

        app(EdtRouteAssignmentService::class)->assign($route, EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]), Carbon::parse('2026-10-01'));

        $this->assertFalse($this->admin->can('delete', $route->fresh()));
    }

    private function municipality(string $code): Municipality
    {
        return Municipality::query()->where('code', $code)->sole();
    }
}
