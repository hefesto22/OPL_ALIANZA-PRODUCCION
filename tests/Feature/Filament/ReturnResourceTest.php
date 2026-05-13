<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Returns\ReturnResource;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests del ReturnResource (devoluciones).
 *
 * Cubre cuatro ejes:
 *  1. Permisos Shield × rol (super_admin / admin / encargado / operador).
 *  2. Aislamiento por bodega en getEloquentQuery — encargado OAC NO ve
 *     devoluciones de OAS, ni siquiera por scroll. El Resource implementa
 *     el filtro inline (no usa WarehouseScope) — ver `if ($user->isWarehouseUser())`.
 *  3. Eager loading congelado — getEagerLoads() debe traer las 5 relaciones
 *     que las columnas usan, para que la tabla NO caiga en N+1 sobre 3.6M
 *     registros. Si alguien remueve un `with()`, este test rompe.
 *  4. Acción de cancelación delegada a ReturnService (regla §8 del CLAUDE.md:
 *     lógica de negocio fuera del Resource).
 *
 * Nota del dominio: las devoluciones son auto-approved al registrarse
 * (memoria project_returns_lifecycle). No diseñamos casos de "aprobar
 * devolución pendiente" porque ese flujo no existe en producción.
 */
class ReturnResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected User $warehouseUser;

    protected User $operator;

    protected Warehouse $warehouseOAC;

    protected Warehouse $warehouseOAS;

    protected function setUp(): void
    {
        parent::setUp();

        $superAdminRole = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $encargadoRole = Role::create(['name' => 'encargado', 'guard_name' => 'web']);
        $operadorRole = Role::create(['name' => 'operador', 'guard_name' => 'web']);

        $returnPerms = [
            'ViewAny:InvoiceReturn', 'View:InvoiceReturn', 'Create:InvoiceReturn',
            'Update:InvoiceReturn', 'Delete:InvoiceReturn',
        ];
        foreach ($returnPerms as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }
        $superAdminRole->givePermissionTo($returnPerms);
        $adminRole->givePermissionTo($returnPerms);
        // Encargado: gestiona devoluciones de su bodega.
        $encargadoRole->givePermissionTo([
            'ViewAny:InvoiceReturn', 'View:InvoiceReturn',
            'Create:InvoiceReturn', 'Update:InvoiceReturn',
        ]);
        // Operador: solo consulta.
        $operadorRole->givePermissionTo(['ViewAny:InvoiceReturn', 'View:InvoiceReturn']);

        $this->warehouseOAC = Warehouse::factory()->oac()->create();
        $this->warehouseOAS = Warehouse::factory()->oas()->create();

        Supplier::factory()->create(['is_active' => true]);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super_admin');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->warehouseUser = User::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $this->warehouseUser->assignRole('encargado');

        $this->operator = User::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $this->operator->assignRole('operador');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Permisos Shield × rol
    // ═══════════════════════════════════════════════════════════════

    public function test_super_admin_has_all_return_permissions(): void
    {
        $this->assertTrue($this->superAdmin->can('ViewAny:InvoiceReturn'));
        $this->assertTrue($this->superAdmin->can('Create:InvoiceReturn'));
        $this->assertTrue($this->superAdmin->can('Update:InvoiceReturn'));
        $this->assertTrue($this->superAdmin->can('Delete:InvoiceReturn'));
    }

    public function test_admin_has_all_return_permissions(): void
    {
        $this->assertTrue($this->admin->can('ViewAny:InvoiceReturn'));
        $this->assertTrue($this->admin->can('Create:InvoiceReturn'));
        $this->assertTrue($this->admin->can('Delete:InvoiceReturn'));
    }

    public function test_warehouse_user_can_view_and_create_but_not_delete(): void
    {
        $this->assertTrue($this->warehouseUser->can('ViewAny:InvoiceReturn'));
        $this->assertTrue($this->warehouseUser->can('Create:InvoiceReturn'));
        $this->assertTrue($this->warehouseUser->can('Update:InvoiceReturn'));
        $this->assertFalse($this->warehouseUser->can('Delete:InvoiceReturn'));
    }

    public function test_operator_can_only_view_returns(): void
    {
        $this->assertTrue($this->operator->can('ViewAny:InvoiceReturn'));
        $this->assertTrue($this->operator->can('View:InvoiceReturn'));
        $this->assertFalse($this->operator->can('Create:InvoiceReturn'));
        $this->assertFalse($this->operator->can('Update:InvoiceReturn'));
    }

    // ═══════════════════════════════════════════════════════════════
    //  Scoping por bodega (filtro inline en getEloquentQuery)
    // ═══════════════════════════════════════════════════════════════

    public function test_warehouse_user_query_filters_returns_by_their_warehouse(): void
    {
        $this->actingAs($this->warehouseUser);

        $manifestOAC = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $manifestOAS = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAS->id]);

        $invoiceOAC = Invoice::factory()->create([
            'manifest_id' => $manifestOAC->id,
            'warehouse_id' => $this->warehouseOAC->id,
        ]);
        $invoiceOAS = Invoice::factory()->create([
            'manifest_id' => $manifestOAS->id,
            'warehouse_id' => $this->warehouseOAS->id,
        ]);

        $visible = InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifestOAC->id,
            'invoice_id' => $invoiceOAC->id,
            'warehouse_id' => $this->warehouseOAC->id,
        ]);
        $hidden = InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifestOAS->id,
            'invoice_id' => $invoiceOAS->id,
            'warehouse_id' => $this->warehouseOAS->id,
        ]);

        $visibleIds = ReturnResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($visible->id, $visibleIds);
        $this->assertNotContains($hidden->id, $visibleIds);
    }

    public function test_super_admin_query_returns_all_returns_across_warehouses(): void
    {
        $this->actingAs($this->superAdmin);

        $manifestOAC = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $manifestOAS = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAS->id]);

        $invoiceOAC = Invoice::factory()->create(['manifest_id' => $manifestOAC->id, 'warehouse_id' => $this->warehouseOAC->id]);
        $invoiceOAS = Invoice::factory()->create(['manifest_id' => $manifestOAS->id, 'warehouse_id' => $this->warehouseOAS->id]);

        $oac = InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifestOAC->id, 'invoice_id' => $invoiceOAC->id, 'warehouse_id' => $this->warehouseOAC->id,
        ]);
        $oas = InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifestOAS->id, 'invoice_id' => $invoiceOAS->id, 'warehouse_id' => $this->warehouseOAS->id,
        ]);

        $visibleIds = ReturnResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($oac->id, $visibleIds);
        $this->assertContains($oas->id, $visibleIds);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Eager loading congelado — guard de N+1
    // ═══════════════════════════════════════════════════════════════

    public function test_return_resource_query_eager_loads_displayed_relations(): void
    {
        // Las columnas de la tabla muestran datos de invoice, returnReason,
        // warehouse, manifest y createdBy. Si alguien remueve un `with()`
        // del Resource, este test rompe — y prevenimos un N+1 sobre 3.6M
        // registros en producción.
        $this->actingAs($this->superAdmin);

        $eagerLoads = ReturnResource::getEloquentQuery()->getEagerLoads();

        $this->assertArrayHasKey('invoice', $eagerLoads);
        $this->assertArrayHasKey('returnReason', $eagerLoads);
        $this->assertArrayHasKey('warehouse', $eagerLoads);
        $this->assertArrayHasKey('manifest', $eagerLoads);
        $this->assertArrayHasKey('createdBy', $eagerLoads);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Acción de dominio — cancelación delegada a Service
    // ═══════════════════════════════════════════════════════════════

    public function test_canceling_return_via_service_marks_it_cancelled(): void
    {
        $this->actingAs($this->superAdmin);

        $manifest = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->warehouseOAC->id,
            'total' => 1000,
        ]);
        InvoiceLine::factory()->for($invoice, 'invoice')->withQuantity(10, 10.0)->create();

        $reason = ReturnReason::factory()->create();
        $return = InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifest->id,
            'invoice_id' => $invoice->id,
            'return_reason_id' => $reason->id,
            'warehouse_id' => $this->warehouseOAC->id,
            'total' => 120.00,
        ]);

        app(ReturnService::class)->cancelReturn($return, 'error de captura');

        $this->assertSame('cancelled', $return->fresh()->status);
    }
}
