<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Catalogs\WarehouseResource;
use App\Filament\Resources\Manifests\ManifestResource;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests para los Filament Resources: acceso por rol, scoping por bodega,
 * lógica de canAccess, navigation badges, y acciones del modelo.
 *
 * Filament 4 + Shield registra Gate::before para super_admin.
 * Para roles con permisos explícitos (admin, encargado), se asignan
 * los permisos de Shield manualmente en setUp.
 */
class ManifestResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $warehouseUser;

    protected Warehouse $warehouseOAC;

    protected Warehouse $warehouseOAS;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles
        $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $adminRole = Role::create(['name' => 'admin',       'guard_name' => 'web']);
        $encargadoRole = Role::create(['name' => 'encargado',   'guard_name' => 'web']);

        // Permisos Shield para Manifest
        $manifestPerms = [
            'ViewAny:Manifest', 'View:Manifest', 'Create:Manifest',
            'Update:Manifest', 'Delete:Manifest',
        ];
        foreach ($manifestPerms as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }
        $superAdminRole->givePermissionTo($manifestPerms);
        $adminRole->givePermissionTo($manifestPerms);
        $encargadoRole->givePermissionTo(['ViewAny:Manifest', 'View:Manifest']);

        // Warehouses
        $this->warehouseOAC = Warehouse::factory()->oac()->create();
        $this->warehouseOAS = Warehouse::factory()->oas()->create();

        Supplier::factory()->create(['is_active' => true]);

        // Users
        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->warehouseUser = User::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $this->warehouseUser->assignRole('encargado');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Policy — permisos por rol
    // ═══════════════════════════════════════════════════════════════

    public function test_super_admin_can_view_any_manifest(): void
    {
        // super_admin tiene todos los permisos vía Spatie
        $this->assertTrue($this->superAdmin->can('ViewAny:Manifest'));
    }

    public function test_admin_can_view_any_manifest(): void
    {
        $this->actingAs($this->admin);
        $this->assertTrue($this->admin->can('ViewAny:Manifest'));
    }

    public function test_admin_can_create_manifest(): void
    {
        $this->assertTrue($this->admin->can('Create:Manifest'));
    }

    public function test_warehouse_user_can_view_but_not_create(): void
    {
        $this->assertTrue($this->warehouseUser->can('ViewAny:Manifest'));
        $this->assertFalse($this->warehouseUser->can('Create:Manifest'));
    }

    public function test_admin_can_update_manifest(): void
    {
        $this->assertTrue($this->admin->can('Update:Manifest'));
    }

    public function test_warehouse_user_cannot_update_manifest(): void
    {
        $this->assertFalse($this->warehouseUser->can('Update:Manifest'));
    }

    // ═══════════════════════════════════════════════════════════════
    //  Eloquent query scoping — bodega del usuario
    // ═══════════════════════════════════════════════════════════════

    public function test_warehouse_user_query_filters_by_their_warehouse(): void
    {
        $this->actingAs($this->warehouseUser);

        // Manifiesto con factura de OAC (bodega del usuario)
        $visible = Manifest::factory()->create(['number' => 'MAN-VIS']);
        Invoice::factory()->create([
            'manifest_id' => $visible->id,
            'warehouse_id' => $this->warehouseOAC->id,
            'status' => 'imported',
        ]);

        // Manifiesto con factura de OAS (otra bodega)
        $hidden = Manifest::factory()->create(['number' => 'MAN-HID']);
        Invoice::factory()->create([
            'manifest_id' => $hidden->id,
            'warehouse_id' => $this->warehouseOAS->id,
            'status' => 'imported',
        ]);

        $visibleIds = ManifestResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($visible->id, $visibleIds);
        $this->assertNotContains($hidden->id, $visibleIds);
    }

    public function test_super_admin_query_returns_all_manifests(): void
    {
        $this->actingAs($this->superAdmin);

        $m1 = Manifest::factory()->create();
        $m2 = Manifest::factory()->create();

        $visibleIds = ManifestResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($m1->id, $visibleIds);
        $this->assertContains($m2->id, $visibleIds);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Navigation badge
    // ═══════════════════════════════════════════════════════════════

    public function test_navigation_badge_shows_imported_count(): void
    {
        $this->actingAs($this->superAdmin);

        Manifest::factory()->create(['status' => 'imported']);
        Manifest::factory()->create(['status' => 'imported']);
        Manifest::factory()->create(['status' => 'closed']);

        $this->assertSame('2', ManifestResource::getNavigationBadge());
    }

    public function test_navigation_badge_null_when_no_imported(): void
    {
        $this->actingAs($this->superAdmin);

        Manifest::factory()->create(['status' => 'closed']);

        $this->assertNull(ManifestResource::getNavigationBadge());
    }

    // ═══════════════════════════════════════════════════════════════
    //  WarehouseResource — acceso restringido por rol
    // ═══════════════════════════════════════════════════════════════

    public function test_super_admin_can_access_warehouse_resource(): void
    {
        $this->actingAs($this->superAdmin);
        $this->assertTrue(WarehouseResource::canAccess());
    }

    public function test_admin_can_access_warehouse_resource(): void
    {
        $this->actingAs($this->admin);
        $this->assertTrue(WarehouseResource::canAccess());
    }

    public function test_warehouse_user_cannot_access_warehouse_resource(): void
    {
        $this->actingAs($this->warehouseUser);
        $this->assertFalse(WarehouseResource::canAccess());
    }

    // ═══════════════════════════════════════════════════════════════
    //  Modelo — close / reopen
    // ═══════════════════════════════════════════════════════════════

    public function test_close_sets_status_and_timestamps(): void
    {
        // Manifest ready-to-close: totales cuadran y hay algo que depositar.
        // El ManifestService valida estas pre-condiciones server-side antes
        // de cerrar — la factory anterior dejaba total_to_deposit=0 y la
        // validación fallaba. Es comportamiento correcto del dominio.
        $manifest = Manifest::factory()->create([
            'status' => 'imported',
            'invoices_count' => 1,
            'total_invoices' => 1000,
            'total_to_deposit' => 1000,
            'total_deposited' => 1000,
            'difference' => 0,
        ]);

        app(\App\Services\ManifestService::class)
            ->closeManifest($manifest, $this->superAdmin->id);

        $manifest->refresh();

        $this->assertSame('closed', $manifest->status);
        $this->assertNotNull($manifest->closed_at);
        $this->assertSame($this->superAdmin->id, $manifest->closed_by);
    }

    public function test_reopen_resets_status(): void
    {
        $manifest = Manifest::factory()->create([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $this->superAdmin->id,
        ]);

        app(\App\Services\ManifestService::class)
            ->reopenManifest($manifest);

        $manifest->refresh();

        $this->assertSame('imported', $manifest->status);
        $this->assertNull($manifest->closed_at);
        $this->assertNull($manifest->closed_by);
    }

    public function test_is_ready_to_close_requires_correct_state(): void
    {
        // Ready: imported, difference=0, total_to_deposit>0
        $ready = Manifest::factory()->create([
            'status' => 'imported',
            'invoices_count' => 1,
            'total_invoices' => 1000,
            'total_to_deposit' => 1000,
            'total_deposited' => 1000,
            'difference' => 0,
        ]);
        $this->assertTrue($ready->isReadyToClose());

        // Closed → not ready
        $closed = Manifest::factory()->create(['status' => 'closed']);
        $this->assertFalse($closed->isReadyToClose());

        // Pending deposit (difference != 0) → not ready
        $unpaid = Manifest::factory()->create([
            'status' => 'imported',
            'total_invoices' => 1000,
            'total_to_deposit' => 1000,
            'total_deposited' => 500,
            'difference' => 500,
        ]);
        $this->assertFalse($unpaid->isReadyToClose());
    }
}
