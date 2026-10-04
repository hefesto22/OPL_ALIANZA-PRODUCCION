<?php

namespace Tests\Feature\Edt;

use App\Filament\Resources\Edt\Suppliers\EdtSupplierResource;
use App\Filament\Resources\Edt\Suppliers\Pages\CreateEdtSupplier;
use App\Filament\Resources\Edt\Suppliers\Pages\EditEdtSupplier;
use App\Filament\Resources\Edt\Suppliers\Pages\ListEdtSuppliers;
use App\Models\Edt\EdtSupplier;
use App\Models\User;
use App\Support\Edt\EdtModule;
use Database\Seeders\EdtPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pantallas de Proveedores del EDT (grupo "EDT Sistema").
 *
 * Los permisos se siembran con EdtPermissionSeeder, el mismo que se corre
 * en cada entorno: si el seeder y la Policy no coinciden, estos tests caen.
 */
class EdtSupplierResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $encargado;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'encargado'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        $this->seed(EdtPermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        // Encargado: entra al panel, pero sin permisos del EDT.
        $this->encargado = User::factory()->create(['is_active' => true]);
        $this->encargado->assignRole('encargado');

        Filament::setCurrentPanel('admin');
    }

    // ── Menú ────────────────────────────────────────────────────────

    public function test_proveedores_esta_en_el_grupo_edt_sistema(): void
    {
        $this->assertSame('EDT Sistema', EdtModule::NAVIGATION_GROUP);
        $this->assertSame(EdtModule::NAVIGATION_GROUP, EdtSupplierResource::getNavigationGroup());
    }

    public function test_el_panel_registra_el_grupo_edt_sistema_antes_de_configuracion(): void
    {
        $labels = collect(Filament::getPanel('admin')->getNavigationGroups())
            ->map(fn (NavigationGroup|string $group): string => $group instanceof NavigationGroup
                ? (string) $group->getLabel()
                : $group)
            ->values()
            ->all();

        $this->assertContains('EDT Sistema', $labels);
        $this->assertLessThan(
            array_search('Configuración', $labels, true),
            array_search('EDT Sistema', $labels, true),
        );
    }

    // ── Acceso ──────────────────────────────────────────────────────

    public function test_el_admin_abre_el_listado_y_ve_los_proveedores(): void
    {
        EdtSupplier::factory()->create(['name' => 'Distribuidora del Valle']);

        $this->actingAs($this->admin)
            ->get(EdtSupplierResource::getUrl('index'))
            ->assertOk()
            // Se guarda en mayúsculas, así que así sale en el listado.
            ->assertSee('DISTRIBUIDORA DEL VALLE');

        $this->assertSame('/edt/proveedores', parse_url(EdtSupplierResource::getUrl('index'), PHP_URL_PATH));
    }

    public function test_un_usuario_sin_permisos_del_edt_recibe_403(): void
    {
        $this->actingAs($this->encargado)
            ->get(EdtSupplierResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_un_usuario_sin_permisos_no_ve_proveedores_en_el_menu(): void
    {
        $this->actingAs($this->encargado);

        // canAccess() es lo que Filament consulta para pintar el ítem del menú.
        $this->assertFalse(EdtSupplierResource::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(EdtSupplierResource::canAccess());
    }

    // ── Listado ─────────────────────────────────────────────────────

    public function test_el_listado_filtra_por_activos(): void
    {
        $activo = EdtSupplier::factory()->create(['code' => 'ACT']);
        $inactivo = EdtSupplier::factory()->inactive()->create(['code' => 'INA']);

        $this->actingAs($this->admin);

        Livewire::test(ListEdtSuppliers::class)
            ->assertCanSeeTableRecords([$activo, $inactivo])
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$activo])
            ->assertCanNotSeeTableRecords([$inactivo]);
    }

    // ── Crear ───────────────────────────────────────────────────────

    public function test_el_formulario_arranca_con_15_por_ciento_y_activo(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtSupplier::class)
            ->assertFormSet([
                'operation_discount_pct' => 15,
                'is_active' => true,
            ]);
    }

    public function test_el_admin_crea_un_proveedor(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtSupplier::class)
            ->fillForm([
                'code' => 'cbc',
                'name' => 'Central America Bottling',
                'rtn' => '0501-1990-123456',
                'contact_name' => 'José Peña',
                'email' => 'Ventas@CBC.com.hn',
                'address' => 'Barrio el Centro, Santa Rosa de Copán',
                'operation_discount_pct' => 15,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('edt_suppliers', [
            'code' => 'CBC',
            'name' => 'CENTRAL AMERICA BOTTLING',
            'contact_name' => 'JOSÉ PEÑA',
            'address' => 'BARRIO EL CENTRO, SANTA ROSA DE COPÁN',
            'email' => 'ventas@cbc.com.hn',
            'rtn' => '05011990123456',
            'operation_discount_pct' => '15.00',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_no_deja_crear_un_codigo_repetido_aunque_cambien_mayusculas(): void
    {
        EdtSupplier::factory()->create(['code' => 'CBC']);

        $this->actingAs($this->admin);

        Livewire::test(CreateEdtSupplier::class)
            ->fillForm([
                'code' => ' cbc ',
                'name' => 'Otro',
                'operation_discount_pct' => 15,
            ])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);

        $this->assertSame(1, EdtSupplier::count());
    }

    public function test_valida_rtn_de_14_digitos(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEdtSupplier::class)
            ->fillForm([
                'code' => 'X1',
                'name' => 'Proveedor',
                'rtn' => '0501-1990-12345', // 13 dígitos
                'operation_discount_pct' => 15,
            ])
            ->call('create')
            ->assertHasFormErrors(['rtn' => 'regex']);
    }

    public function test_valida_el_rango_del_descuento(): void
    {
        $this->actingAs($this->admin);

        foreach ([100, -1] as $invalido) {
            Livewire::test(CreateEdtSupplier::class)
                ->fillForm([
                    'code' => 'X1',
                    'name' => 'Proveedor',
                    'operation_discount_pct' => $invalido,
                ])
                ->call('create')
                ->assertHasFormErrors(['operation_discount_pct']);
        }

        $this->assertSame(0, EdtSupplier::count());
    }

    // ── Editar ──────────────────────────────────────────────────────

    public function test_el_admin_edita_el_descuento_y_queda_en_la_bitacora(): void
    {
        $supplier = EdtSupplier::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test(EditEdtSupplier::class, ['record' => $supplier->getRouteKey()])
            ->fillForm(['operation_discount_pct' => 12.5])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('12.50', $supplier->fresh()->operation_discount_pct);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => EdtModule::LOG_NAME,
            'event' => 'updated',
            'subject_id' => $supplier->id,
            'causer_id' => $this->admin->id,
        ]);
    }

    public function test_al_editar_puede_conservar_su_propio_codigo(): void
    {
        $supplier = EdtSupplier::factory()->create(['code' => 'CBC']);

        $this->actingAs($this->admin);

        // ignoreRecord: guardar sin cambiar el código no es "repetido".
        Livewire::test(EditEdtSupplier::class, ['record' => $supplier->getRouteKey()])
            ->fillForm(['name' => 'Nombre nuevo'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('NOMBRE NUEVO', $supplier->fresh()->name);
    }

    public function test_el_admin_borra_un_proveedor_desde_editar(): void
    {
        $supplier = EdtSupplier::factory()->create();

        $this->actingAs($this->admin);

        Livewire::test(EditEdtSupplier::class, ['record' => $supplier->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('edt_suppliers', ['id' => $supplier->id]);
    }
}
