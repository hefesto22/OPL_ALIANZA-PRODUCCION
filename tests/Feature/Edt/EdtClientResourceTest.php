<?php

namespace Tests\Feature\Edt;

use App\Filament\Resources\Edt\Clients\EdtClientResource;
use App\Filament\Resources\Edt\Clients\Pages\CreateEdtClient;
use App\Filament\Resources\Edt\Clients\Pages\EditEdtClient;
use App\Filament\Resources\Edt\Clients\Pages\ListEdtClients;
use App\Models\Edt\EdtClient;
use App\Models\Geo\Department;
use App\Models\Geo\Municipality;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Edt\EdtModule;
use Database\Seeders\EdtPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pantallas de Clientes del EDT: aislamiento por bodega, código manual o
 * automático, zona del catálogo y crédito opcional.
 */
class EdtClientResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $oacUser;

    private Warehouse $oac;

    private Warehouse $oas;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'encargado'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        $this->seed(EdtPermissionSeeder::class);

        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        // Usuario de la bodega OAC con los permisos de clientes (sin borrar).
        $this->oacUser = User::factory()->forWarehouse($this->oac)->create(['is_active' => true]);
        $this->oacUser->assignRole('encargado');
        $this->oacUser->givePermissionTo(['ViewAny:EdtClient', 'View:EdtClient', 'Create:EdtClient', 'Update:EdtClient']);

        Filament::setCurrentPanel('admin');
    }

    // ── Menú y acceso ───────────────────────────────────────────────

    public function test_clientes_esta_en_el_grupo_edt_sistema(): void
    {
        $this->assertSame(EdtModule::NAVIGATION_GROUP, EdtClientResource::getNavigationGroup());
        $this->assertSame('/edt/clientes', parse_url(EdtClientResource::getUrl('index'), PHP_URL_PATH));
    }

    public function test_un_usuario_sin_permisos_del_edt_recibe_403(): void
    {
        $encargado = User::factory()->forWarehouse($this->oac)->create(['is_active' => true]);
        $encargado->assignRole('encargado');

        $this->actingAs($encargado)
            ->get(EdtClientResource::getUrl('index'))
            ->assertForbidden();
    }

    // ── Aislamiento por bodega ──────────────────────────────────────

    public function test_el_admin_ve_los_clientes_de_todas_las_bodegas(): void
    {
        $own = $this->client($this->oac);
        $other = $this->client($this->oas);

        $this->actingAs($this->admin);

        Livewire::test(ListEdtClients::class)
            ->assertCanSeeTableRecords([$own, $other]);
    }

    public function test_un_usuario_de_bodega_solo_ve_los_clientes_de_su_bodega(): void
    {
        $own = $this->client($this->oac);
        $other = $this->client($this->oas);

        $this->actingAs($this->oacUser);

        Livewire::test(ListEdtClients::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_un_usuario_de_bodega_no_abre_por_url_un_cliente_de_otra_bodega(): void
    {
        $other = $this->client($this->oas);

        $this->actingAs($this->oacUser)
            ->get(EdtClientResource::getUrl('edit', ['record' => $other]))
            ->assertNotFound();
    }

    public function test_un_usuario_de_bodega_no_puede_crear_en_otra_bodega(): void
    {
        $this->actingAs($this->oacUser);

        Livewire::test(CreateEdtClient::class)
            ->fillForm($this->validData(['warehouse_id' => $this->oas->id]))
            ->call('create')
            ->assertHasFormErrors(['warehouse_id']);

        $this->assertSame(0, EdtClient::query()->count());
    }

    public function test_con_una_sola_bodega_ya_viene_elegida_con_su_departamento(): void
    {
        $this->actingAs($this->oacUser);

        Livewire::test(CreateEdtClient::class)
            ->assertFormSet([
                'warehouse_id' => $this->oac->id,
                'department_id' => $this->department('04')->id, // COPÁN
                'credit_enabled' => false,
                'is_active' => true,
            ]);
    }

    // ── Alta ────────────────────────────────────────────────────────

    public function test_crear_sin_codigo_asigna_el_automatico(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtClient::class)
            ->fillForm($this->validData(['code' => null, 'name' => 'juan pérez']))
            ->call('create')
            ->assertHasNoFormErrors();

        $client = EdtClient::query()->sole();
        $this->assertMatchesRegularExpression('/^C-\d{6}$/', $client->code);
        $this->assertSame('JUAN PÉREZ', $client->name);
        $this->assertSame('SANTA ROSA DE COPÁN', $client->municipality->name);
        $this->assertSame($this->admin->id, $client->created_by);
    }

    public function test_crear_con_codigo_escrito_y_que_no_se_repita(): void
    {
        $this->client($this->oac, ['code' => 'CLI-01']);
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtClient::class)
            ->fillForm($this->validData(['code' => ' cli-01 ']))
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);

        Livewire::test(CreateEdtClient::class)
            ->fillForm($this->validData(['code' => 'cli-02']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(EdtClient::query()->where('code', 'CLI-02')->exists());
    }

    public function test_valida_los_datos_obligatorios_y_el_rtn(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtClient::class)
            ->fillForm([
                'name' => null,
                'warehouse_id' => null,
                'department_id' => null,
                'municipality_id' => null,
                'rtn' => '0401-1990',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'required',
                'warehouse_id' => 'required',
                'department_id' => 'required',
                'municipality_id' => 'required',
                'rtn' => 'regex',
            ]);
    }

    public function test_el_municipio_tiene_que_ser_del_departamento_elegido(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtClient::class)
            ->fillForm($this->validData([
                'department_id' => $this->department('04')->id,            // COPÁN
                'municipality_id' => $this->municipality('1416')->id,      // SINUAPA, Ocotepeque
            ]))
            ->call('create')
            ->assertHasFormErrors(['municipality_id']);

        $this->assertSame(0, EdtClient::query()->count());
    }

    public function test_al_elegir_bodega_propone_su_departamento_y_cambiarlo_limpia_el_municipio(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtClient::class)
            ->fillForm(['warehouse_id' => $this->oas->id])
            ->assertFormSet(['department_id' => $this->department('16')->id]) // SANTA BÁRBARA
            ->fillForm(['municipality_id' => $this->municipality('1606')->id])
            ->fillForm(['department_id' => $this->department('14')->id])
            ->assertFormSet(['municipality_id' => null]);
    }

    // ── Crédito ─────────────────────────────────────────────────────

    public function test_crear_con_credito_y_luego_quitarlo(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtClient::class)
            ->fillForm($this->validData(['credit_enabled' => true, 'credit_limit' => '15000', 'credit_days' => 30]))
            ->call('create')
            ->assertHasNoFormErrors();

        $client = EdtClient::query()->sole();
        $this->assertTrue($client->credit_enabled);
        $this->assertSame('15000.00', $client->credit_limit);
        $this->assertSame(30, $client->credit_days);

        Livewire::test(EditEdtClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['credit_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $client->refresh();
        $this->assertFalse($client->credit_enabled);
        $this->assertNull($client->credit_limit);
        $this->assertNull($client->credit_days);
    }

    public function test_con_credito_el_limite_y_los_dias_son_opcionales_pero_validos(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtClient::class)
            ->fillForm($this->validData(['credit_enabled' => true, 'credit_limit' => '0', 'credit_days' => 400]))
            ->call('create')
            ->assertHasFormErrors(['credit_limit', 'credit_days']);

        Livewire::test(CreateEdtClient::class)
            ->fillForm($this->validData(['credit_enabled' => true, 'credit_limit' => null, 'credit_days' => null]))
            ->call('create')
            ->assertHasNoFormErrors();

        $client = EdtClient::query()->sole();
        $this->assertTrue($client->credit_enabled);
        $this->assertNull($client->credit_limit);
    }

    // ── Listado ─────────────────────────────────────────────────────

    public function test_el_listado_muestra_codigo_zona_bodega_y_credito(): void
    {
        $this->client($this->oac, [
            'code' => 'CLI-77',
            'name' => 'JUAN PÉREZ',
            'business_name' => 'PULPERÍA LA BENDICIÓN',
            'credit_enabled' => true,
            'credit_limit' => '15000',
            'credit_days' => 30,
        ]);
        $this->client($this->oac, ['name' => 'MARÍA LÓPEZ']);

        $this->actingAs($this->admin)
            ->get(EdtClientResource::getUrl('index'))
            ->assertOk()
            ->assertSee('JUAN PÉREZ')
            ->assertSee('CLI-77 · PULPERÍA LA BENDICIÓN')
            ->assertSee('SANTA ROSA DE COPÁN')
            ->assertSee('OAC')
            ->assertSee('L 15,000.00')
            ->assertSee('30 días')
            ->assertSee('Contado');
    }

    public function test_busca_por_codigo_nombre_o_negocio_y_filtra_por_municipio_y_credito(): void
    {
        $juan = $this->client($this->oac, ['code' => 'CLI-1', 'name' => 'JUAN', 'business_name' => 'PULPERÍA SOL'], '0401');
        $maria = $this->client($this->oac, ['code' => 'CLI-2', 'name' => 'MARÍA', 'business_name' => 'MINISÚPER LUNA'], '0413');
        $maria->update(['credit_enabled' => true]);

        $this->actingAs($this->admin);

        Livewire::test(ListEdtClients::class)
            ->searchTable('cli-2')
            ->assertCanSeeTableRecords([$maria])
            ->assertCanNotSeeTableRecords([$juan])
            ->searchTable('pulpería sol')
            ->assertCanSeeTableRecords([$juan])
            ->assertCanNotSeeTableRecords([$maria]);

        Livewire::test(ListEdtClients::class)
            ->filterTable('municipality_id', [$this->municipality('0413')->id]) // NUEVA ARCADIA
            ->assertCanSeeTableRecords([$maria])
            ->assertCanNotSeeTableRecords([$juan]);

        Livewire::test(ListEdtClients::class)
            ->filterTable('credit_enabled', false)
            ->assertCanSeeTableRecords([$juan])
            ->assertCanNotSeeTableRecords([$maria]);
    }

    // ── Edición ─────────────────────────────────────────────────────

    public function test_editar_guarda_en_mayusculas_y_el_codigo_no_puede_quedar_vacio(): void
    {
        $client = $this->client($this->oac);
        $this->actingAs($this->oacUser);

        Livewire::test(EditEdtClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['code' => null])
            ->call('save')
            ->assertHasFormErrors(['code' => 'required']);

        Livewire::test(EditEdtClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['business_name' => 'abarrotería mejía'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ABARROTERÍA MEJÍA', $client->fresh()->business_name);
        $this->assertSame($this->oacUser->id, $client->fresh()->updated_by);
    }

    public function test_borrar_solo_aparece_con_el_permiso(): void
    {
        $client = $this->client($this->oac);

        $this->actingAs($this->oacUser);
        Livewire::test(EditEdtClient::class, ['record' => $client->getRouteKey()])
            ->assertActionHidden('delete');

        $this->actingAs($this->admin);
        Livewire::test(EditEdtClient::class, ['record' => $client->getRouteKey()])
            ->callAction('delete');

        $this->assertModelMissing($client);
    }

    // ── Ayudas ──────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validData(array $overrides = []): array
    {
        return array_merge([
            'code' => null,
            'name' => 'CLIENTE DE PRUEBA',
            'warehouse_id' => $this->oac->id,
            'department_id' => $this->department('04')->id,
            'municipality_id' => $this->municipality('0401')->id,
            'credit_enabled' => false,
            'is_active' => true,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function client(Warehouse $warehouse, array $attributes = [], string $municipality = '0401'): EdtClient
    {
        return EdtClient::factory()
            ->inMunicipality($municipality)
            ->create(['warehouse_id' => $warehouse->id, ...$attributes]);
    }

    private function department(string $code): Department
    {
        return Department::query()->where('code', $code)->sole();
    }

    private function municipality(string $code): Municipality
    {
        return Municipality::query()->where('code', $code)->sole();
    }
}
