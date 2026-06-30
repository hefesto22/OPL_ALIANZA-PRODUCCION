<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Deposits\DepositResource;
use App\Models\Deposit;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests del DepositResource.
 *
 * Cubre los ejes financieros — depósitos son dinero, su aislamiento por
 * bodega y permisos por rol son la diferencia entre auditable y fuga de
 * datos financieros.
 *
 *  1. Permisos Shield × rol (super_admin / admin / finance / encargado).
 *     Finance es el rol específico de depósitos (memoria deploy_status).
 *  2. Visibilidad por JERARQUÍA de creación (created_by) — Deposit::scopeVisibleTo.
 *     Cada usuario ve los depósitos que registró él y los de su subárbol
 *     (usuarios que creó, transitivamente); su superior (quien lo creó) los
 *     ve, pero un par de otra bodega NO. super_admin ve todos. Reemplaza el
 *     scoping anterior por manifest.warehouse_id, que ocultaba el depósito
 *     propio del encargado en manifiestos multi-bodega.
 *  3. Eager loading congelado — getEagerLoads() debe traer manifest y
 *     createdBy porque las columnas de la tabla los usan.
 *
 * Notas del dominio: los depósitos no se pueden borrar si el manifest
 * está cerrado (regla del Resource vía ->hidden en DeleteAction). Esa
 * regla vive en la UI, no en el Service — los tests del Service ya
 * cubren la integridad transaccional (lockForUpdate + recalc en TX).
 */
class DepositResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $finance;

    protected User $warehouseUser;

    protected Warehouse $warehouseOAC;

    protected Warehouse $warehouseOAS;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $financeRole = Role::create(['name' => 'finance', 'guard_name' => 'web']);
        $encargadoRole = Role::create(['name' => 'encargado', 'guard_name' => 'web']);

        $depositPerms = [
            'ViewAny:Deposit', 'View:Deposit', 'Create:Deposit',
            'Update:Deposit', 'Delete:Deposit',
        ];
        foreach ($depositPerms as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }
        $superAdminRole->givePermissionTo($depositPerms);
        $adminRole->givePermissionTo($depositPerms);
        $financeRole->givePermissionTo($depositPerms);
        // Encargado NO accede a depósitos — finance es el rol específico.

        $this->warehouseOAC = Warehouse::factory()->oac()->create();
        $this->warehouseOAS = Warehouse::factory()->oas()->create();

        Supplier::factory()->create(['is_active' => true]);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        // Finance NO es usuario de bodega — ve todo (no warehouse_id).
        $this->finance = User::factory()->create();
        $this->finance->assignRole('finance');

        $this->warehouseUser = User::factory()->forWarehouse($this->warehouseOAC)->create();
        $this->warehouseUser->assignRole('encargado');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Permisos Shield × rol
    // ═══════════════════════════════════════════════════════════════

    public function test_super_admin_has_all_deposit_permissions(): void
    {
        $this->assertTrue($this->superAdmin->can('ViewAny:Deposit'));
        $this->assertTrue($this->superAdmin->can('Create:Deposit'));
        $this->assertTrue($this->superAdmin->can('Update:Deposit'));
        $this->assertTrue($this->superAdmin->can('Delete:Deposit'));
    }

    public function test_admin_has_all_deposit_permissions(): void
    {
        $this->assertTrue($this->admin->can('ViewAny:Deposit'));
        $this->assertTrue($this->admin->can('Create:Deposit'));
        $this->assertTrue($this->admin->can('Delete:Deposit'));
    }

    public function test_finance_has_all_deposit_permissions(): void
    {
        $this->assertTrue($this->finance->can('ViewAny:Deposit'));
        $this->assertTrue($this->finance->can('Create:Deposit'));
        $this->assertTrue($this->finance->can('Update:Deposit'));
        $this->assertTrue($this->finance->can('Delete:Deposit'));
    }

    public function test_encargado_cannot_access_deposits(): void
    {
        // Encargado es supervisor de bodega — no toca depósitos.
        $this->assertFalse($this->warehouseUser->can('ViewAny:Deposit'));
        $this->assertFalse($this->warehouseUser->can('Create:Deposit'));
    }

    // ═══════════════════════════════════════════════════════════════
    //  Visibilidad por jerarquía de creación (created_by)
    // ═══════════════════════════════════════════════════════════════

    public function test_user_sees_own_deposits_but_not_a_siblings(): void
    {
        // Jerarquía: un admin (M) creó a dos encargados de bodegas distintas.
        // Cada encargado registra un depósito en el MISMO manifiesto (multi-bodega).
        // Un encargado ve el suyo pero NO el del otro — aunque compartan manifiesto.
        $manager = User::factory()->create();
        $manager->assignRole('admin');

        $encOAC = User::factory()->forWarehouse($this->warehouseOAC)->create(['created_by' => $manager->id]);
        $encOAC->givePermissionTo('ViewAny:Deposit');
        $encOAS = User::factory()->forWarehouse($this->warehouseOAS)->create(['created_by' => $manager->id]);
        $encOAS->givePermissionTo('ViewAny:Deposit');

        $manifest = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $depositOAC = Deposit::factory()->create(['manifest_id' => $manifest->id, 'created_by' => $encOAC->id]);
        $depositOAS = Deposit::factory()->create(['manifest_id' => $manifest->id, 'created_by' => $encOAS->id]);

        $this->actingAs($encOAC);
        $visibleIds = DepositResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($depositOAC->id, $visibleIds);
        $this->assertNotContains($depositOAS->id, $visibleIds);
    }

    public function test_supervisor_sees_deposits_created_by_their_descendants(): void
    {
        // El usuario que creó al encargado (su superior) SÍ ve sus depósitos —
        // está arriba en la cadena created_by.
        $manager = User::factory()->create();
        $manager->assignRole('admin');
        $manager->givePermissionTo('ViewAny:Deposit');

        $encargado = User::factory()->forWarehouse($this->warehouseOAC)->create(['created_by' => $manager->id]);

        $manifest = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $deposit = Deposit::factory()->create(['manifest_id' => $manifest->id, 'created_by' => $encargado->id]);

        $this->actingAs($manager);
        $visibleIds = DepositResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($deposit->id, $visibleIds);
    }

    public function test_super_admin_query_returns_all_deposits(): void
    {
        // super_admin no se filtra por jerarquía — ve todo, sin importar quién creó.
        $other = User::factory()->create();
        $manifest = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $d1 = Deposit::factory()->create(['manifest_id' => $manifest->id, 'created_by' => $other->id]);
        $d2 = Deposit::factory()->create(['manifest_id' => $manifest->id, 'created_by' => $this->admin->id]);

        $this->actingAs($this->superAdmin);
        $visibleIds = DepositResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($d1->id, $visibleIds);
        $this->assertContains($d2->id, $visibleIds);
    }

    public function test_non_super_admin_does_not_see_deposits_outside_their_subtree(): void
    {
        // finance (global pero NO super_admin) se filtra por jerarquía: un
        // depósito hecho por alguien que NO está en su subárbol no le aparece.
        $other = User::factory()->create();
        $manifest = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $foreign = Deposit::factory()->create(['manifest_id' => $manifest->id, 'created_by' => $other->id]);

        $this->actingAs($this->finance);
        $visibleIds = DepositResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertNotContains($foreign->id, $visibleIds);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Export Excel — consistente con la visibilidad por jerarquía
    // ═══════════════════════════════════════════════════════════════

    public function test_deposit_export_filters_by_visible_user_ids(): void
    {
        $other = User::factory()->create();
        $manifest = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $mine = Deposit::factory()->create(['manifest_id' => $manifest->id, 'created_by' => $this->admin->id]);
        $foreign = Deposit::factory()->create(['manifest_id' => $manifest->id, 'created_by' => $other->id]);

        $export = new \App\Exports\DepositsExport(visibleUserIds: [$this->admin->id]);
        $ids = $export->query()->pluck('id')->toArray();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_deposit_export_with_null_visible_ids_returns_all(): void
    {
        // null = super_admin → sin filtro.
        $other = User::factory()->create();
        $manifest = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $deposit = Deposit::factory()->create(['manifest_id' => $manifest->id, 'created_by' => $other->id]);

        $export = new \App\Exports\DepositsExport(visibleUserIds: null);
        $ids = $export->query()->pluck('id')->toArray();

        $this->assertContains($deposit->id, $ids);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Eager loading congelado — guard de N+1
    // ═══════════════════════════════════════════════════════════════

    public function test_deposit_resource_query_eager_loads_manifest_and_created_by(): void
    {
        // Las columnas usan manifest.number, manifest.status y createdBy.name.
        // Si alguien remueve un `with()`, este test rompe — prevenimos N+1.
        $this->actingAs($this->superAdmin);

        $eagerLoads = DepositResource::getEloquentQuery()->getEagerLoads();

        $this->assertArrayHasKey('manifest', $eagerLoads);
        $this->assertArrayHasKey('createdBy', $eagerLoads);
    }
}
