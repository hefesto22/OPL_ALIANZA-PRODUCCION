<?php

namespace Tests\Feature\Edt;

use App\Filament\Resources\Edt\Clients\EdtClientResource;
use App\Filament\Resources\Edt\Clients\Pages\ListEdtClients;
use App\Filament\Resources\Edt\Products\EdtProductResource;
use App\Models\Edt\EdtClient;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtRoute;
use App\Models\Edt\EdtSeller;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Edt\EdtRouteAssignmentService;
use App\Services\Edt\EdtSellerZones;
use App\Support\Edt\EdtModule;
use Database\Seeders\EdtPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Qué clientes ve un vendedor: los de su bodega, en los municipios de sus
 * rutas vigentes; con "Solo ruta de hoy", los de las rutas de ese día.
 */
class EdtSellerZonesTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $oac;

    private Warehouse $oas;

    private EdtSeller $juan;

    private EdtClient $santaRosa;

    private EdtClient $dolores;

    private EdtClient $corquin;

    private EdtClient $santaRosaOas;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 09:00:00'); // lunes

        foreach (['super_admin', 'admin', 'encargado'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        $this->seed(EdtPermissionSeeder::class);
        Filament::setCurrentPanel('admin');

        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();

        $this->juan = EdtSeller::factory()->create(['warehouse_id' => $this->oac->id]);

        // Ruta de los lunes (Santa Rosa) y ruta sin día (Dolores), ambas de Juan.
        $lunes = EdtRoute::factory()->covering(['0401'])->visitingOn([1])->create(['warehouse_id' => $this->oac->id]);
        $libre = EdtRoute::factory()->covering(['0407'])->create(['warehouse_id' => $this->oac->id]);
        app(EdtRouteAssignmentService::class)->assign($lunes, $this->juan, Carbon::parse('2026-10-01'));
        app(EdtRouteAssignmentService::class)->assign($libre, $this->juan, Carbon::parse('2026-10-01'));

        $this->santaRosa = $this->client($this->oac, '0401');
        $this->dolores = $this->client($this->oac, '0407');
        $this->corquin = $this->client($this->oac, '0405');          // fuera de sus rutas
        $this->santaRosaOas = $this->client($this->oas, '0401');     // mismo municipio, otra bodega
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_el_vendedor_ve_los_clientes_de_sus_rutas_en_su_bodega(): void
    {
        $ids = EdtSellerZones::applyToClients(EdtClient::query(), $this->juan)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->santaRosa->id, $this->dolores->id], $ids);
    }

    public function test_ruta_de_hoy_respeta_los_dias_de_visita(): void
    {
        $monday = EdtSellerZones::applyToClients(EdtClient::query(), $this->juan, Carbon::parse('2026-10-05'), onlyVisitDay: true)->pluck('id')->all();
        $tuesday = EdtSellerZones::applyToClients(EdtClient::query(), $this->juan, Carbon::parse('2026-10-06'), onlyVisitDay: true)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->santaRosa->id, $this->dolores->id], $monday);
        // El martes no toca Santa Rosa; Dolores no tiene día, va siempre.
        $this->assertEqualsCanonicalizing([$this->dolores->id], $tuesday);
    }

    public function test_solo_cuentan_las_asignaciones_vigentes_y_las_rutas_activas(): void
    {
        $nueva = EdtRoute::factory()->covering(['0405'])->create(['warehouse_id' => $this->oac->id]);
        app(EdtRouteAssignmentService::class)->assign($nueva, $this->juan, Carbon::parse('2026-10-20'));

        // Corquín empieza el 20: hoy no, ese día sí.
        $this->assertNotContains($this->corquin->id, EdtSellerZones::applyToClients(EdtClient::query(), $this->juan)->pluck('id'));
        $this->assertContains($this->corquin->id, EdtSellerZones::applyToClients(EdtClient::query(), $this->juan, Carbon::parse('2026-10-20'))->pluck('id'));

        // Ruta desactivada: deja de contar.
        EdtRoute::query()->whereHas('municipalities', fn ($q) => $q->where('code', '0407'))->update(['is_active' => false]);
        $this->assertNotContains($this->dolores->id, EdtSellerZones::applyToClients(EdtClient::query(), $this->juan)->pluck('id'));
    }

    public function test_en_la_pantalla_el_vendedor_solo_ve_sus_clientes(): void
    {
        $this->actingAs($this->juan->user);

        Livewire::test(ListEdtClients::class)
            ->assertCanSeeTableRecords([$this->santaRosa, $this->dolores])
            ->assertCanNotSeeTableRecords([$this->corquin, $this->santaRosaOas])
            ->filterTable('today_route', true)
            ->assertCanSeeTableRecords([$this->santaRosa, $this->dolores]);
    }

    public function test_al_vendedor_no_le_aparecen_los_clientes_inactivos(): void
    {
        $this->dolores->update(['is_active' => false]);
        $this->actingAs($this->juan->user);

        Livewire::test(ListEdtClients::class)
            ->assertCanSeeTableRecords([$this->santaRosa])
            ->assertCanNotSeeTableRecords([$this->dolores]);

        $this->assertFalse($this->juan->user->can('view', $this->dolores->fresh()));
        // Para la zona en sí (lo que usan admin y encargado) sigue contando.
        $this->assertContains($this->dolores->id, EdtSellerZones::applyToClients(EdtClient::query(), $this->juan)->pluck('id'));
    }

    public function test_el_vendedor_no_abre_por_url_un_cliente_fuera_de_sus_rutas(): void
    {
        $this->actingAs($this->juan->user)
            ->get(EdtClientResource::getUrl('edit', ['record' => $this->corquin]))
            ->assertNotFound();

        $this->assertTrue($this->juan->user->can('view', $this->santaRosa));
        $this->assertFalse($this->juan->user->can('view', $this->corquin));
        // El vendedor solo consulta: no edita ni crea clientes.
        $this->assertFalse($this->juan->user->can('update', $this->santaRosa));
        $this->assertFalse($this->juan->user->can('create', EdtClient::class));
    }

    public function test_un_usuario_con_rol_de_vendedor_sin_ficha_no_ve_clientes(): void
    {
        $user = User::factory()->forWarehouse($this->oac)->create(['is_active' => true]);
        $user->assignRole(EdtModule::SELLER_ROLE);

        $this->assertSame(0, EdtSellerZones::applyForUser(EdtClient::query(), $user)->count());
        $this->assertFalse($user->can('view', $this->santaRosa));
    }

    public function test_el_encargado_ve_todos_los_de_su_bodega_y_filtra_por_ruta(): void
    {
        $encargado = User::factory()->forWarehouse($this->oac)->create(['is_active' => true]);
        $encargado->assignRole('encargado');
        $route = EdtRoute::query()->whereHas('municipalities', fn ($q) => $q->where('code', '0401'))->sole();

        $this->actingAs($encargado);

        Livewire::test(ListEdtClients::class)
            ->assertCanSeeTableRecords([$this->santaRosa, $this->dolores, $this->corquin])
            ->assertCanNotSeeTableRecords([$this->santaRosaOas])
            ->filterTable('route', $route->id)
            ->assertCanSeeTableRecords([$this->santaRosa])
            ->assertCanNotSeeTableRecords([$this->dolores, $this->corquin]);
    }

    public function test_el_vendedor_ve_precios_de_venta_pero_no_costo_ni_margen(): void
    {
        EdtProduct::factory()->create(['description' => 'CR GALLO LATA 350ML CT', 'list_price' => '17.60', 'isv_pct' => 0, 'units_per_box' => 24]);

        $this->actingAs($this->juan->user)
            ->get(EdtProductResource::getUrl('index'))
            ->assertOk()
            ->assertSee('CR GALLO LATA 350ML CT')
            ->assertSee('L 422.40')            // detalle por caja
            ->assertDontSee('L 14.96')         // costo por unidad: 17.60 × 0.85
            ->assertDontSee('Lista L 17.60')   // lista del proveedor, debajo del costo
            ->assertDontSee('Margen ');

        $this->assertFalse($this->juan->user->can('changePrice', EdtProduct::query()->first()));
    }

    public function test_el_vendedor_entra_al_panel_y_si_lo_desactivan_ya_no(): void
    {
        $this->assertTrue($this->juan->user->canAccessPanel(Filament::getPanel('admin')));

        $this->juan->user->update(['is_active' => false]);

        $this->assertFalse($this->juan->user->fresh()->canAccessPanel(Filament::getPanel('admin')));
    }

    private function client(Warehouse $warehouse, string $municipality): EdtClient
    {
        return EdtClient::factory()->inMunicipality($municipality)->create(['warehouse_id' => $warehouse->id]);
    }
}
