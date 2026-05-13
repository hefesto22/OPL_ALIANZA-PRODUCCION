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
 *  2. Scoping vía WarehouseScope::applyViaRelation('manifest') — Deposit
 *     no tiene warehouse_id directo; se filtra a través del manifiesto.
 *     Un encargado de OAC NO ve depósitos de manifiestos de OAS, aunque
 *     no haya warehouse_id en la tabla deposits.
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
        $this->finance = User::factory()->create(['warehouse_id' => null]);
        $this->finance->assignRole('finance');

        $this->warehouseUser = User::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
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
    //  Scoping vía WarehouseScope::applyViaRelation('manifest')
    // ═══════════════════════════════════════════════════════════════

    public function test_warehouse_user_query_filters_deposits_via_manifest_warehouse(): void
    {
        // Aunque la tabla deposits no tiene warehouse_id, el filtro debe
        // aplicar a través de manifest.warehouse_id usando whereHas.
        // Para este test damos al encargado permiso ViewAny:Deposit ad-hoc;
        // lo que importa es el contrato del query, no la matriz de roles.
        $this->warehouseUser->givePermissionTo('ViewAny:Deposit');
        $this->actingAs($this->warehouseUser);

        $manifestOAC = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $manifestOAS = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAS->id]);

        $visible = Deposit::factory()->create(['manifest_id' => $manifestOAC->id]);
        $hidden = Deposit::factory()->create(['manifest_id' => $manifestOAS->id]);

        $visibleIds = DepositResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($visible->id, $visibleIds);
        $this->assertNotContains($hidden->id, $visibleIds);
    }

    public function test_super_admin_query_returns_all_deposits_across_warehouses(): void
    {
        $this->actingAs($this->superAdmin);

        $manifestOAC = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $manifestOAS = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAS->id]);

        $oac = Deposit::factory()->create(['manifest_id' => $manifestOAC->id]);
        $oas = Deposit::factory()->create(['manifest_id' => $manifestOAS->id]);

        $visibleIds = DepositResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($oac->id, $visibleIds);
        $this->assertContains($oas->id, $visibleIds);
    }

    public function test_finance_user_without_warehouse_sees_all_deposits(): void
    {
        // Finance no tiene warehouse_id → no es scoped → ve todo.
        $this->actingAs($this->finance);

        $manifestOAC = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $manifestOAS = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAS->id]);

        $oac = Deposit::factory()->create(['manifest_id' => $manifestOAC->id]);
        $oas = Deposit::factory()->create(['manifest_id' => $manifestOAS->id]);

        $visibleIds = DepositResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($oac->id, $visibleIds);
        $this->assertContains($oas->id, $visibleIds);
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
