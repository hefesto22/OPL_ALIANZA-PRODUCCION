<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Invoices\InvoiceResource;
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
 * Tests del InvoiceResource.
 *
 * InvoiceResource es minimal por diseño: existe para que FilamentShield
 * descubra el modelo Invoice y genere los permisos `*:Invoice`, pero no
 * tiene navegación ni pages propios — las facturas se consultan dentro de
 * ManifestResource vía InvoicesRelationManager.
 *
 * Por eso este test cubre:
 *  1. Permisos Shield × rol (super_admin / admin / encargado / operador)
 *  2. Scoping por bodega vía WarehouseScope::apply (operador OAC NO ve OAS)
 *  3. Que getEloquentQuery() ignora SoftDeletingScope (decisión explícita
 *     del Resource — necesario para investigar facturas borradas).
 *  4. Que el Resource está oculto del menú lateral.
 */
class InvoiceResourceTest extends TestCase
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

        $invoicePerms = [
            'ViewAny:Invoice', 'View:Invoice', 'Create:Invoice',
            'Update:Invoice', 'Delete:Invoice',
        ];
        foreach ($invoicePerms as $perm) {
            Permission::create(['name' => $perm, 'guard_name' => 'web']);
        }
        $superAdminRole->givePermissionTo($invoicePerms);
        $adminRole->givePermissionTo($invoicePerms);
        // Encargado ve y edita facturas de su bodega; no crea ni borra.
        $encargadoRole->givePermissionTo(['ViewAny:Invoice', 'View:Invoice', 'Update:Invoice']);
        // Operador solo consulta.
        $operadorRole->givePermissionTo(['ViewAny:Invoice', 'View:Invoice']);

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

    public function test_super_admin_has_all_invoice_permissions(): void
    {
        $this->assertTrue($this->superAdmin->can('ViewAny:Invoice'));
        $this->assertTrue($this->superAdmin->can('Create:Invoice'));
        $this->assertTrue($this->superAdmin->can('Update:Invoice'));
        $this->assertTrue($this->superAdmin->can('Delete:Invoice'));
    }

    public function test_admin_has_all_invoice_permissions(): void
    {
        $this->assertTrue($this->admin->can('ViewAny:Invoice'));
        $this->assertTrue($this->admin->can('Create:Invoice'));
        $this->assertTrue($this->admin->can('Update:Invoice'));
        $this->assertTrue($this->admin->can('Delete:Invoice'));
    }

    public function test_warehouse_user_can_view_and_update_but_not_create_or_delete(): void
    {
        $this->assertTrue($this->warehouseUser->can('ViewAny:Invoice'));
        $this->assertTrue($this->warehouseUser->can('View:Invoice'));
        $this->assertTrue($this->warehouseUser->can('Update:Invoice'));
        $this->assertFalse($this->warehouseUser->can('Create:Invoice'));
        $this->assertFalse($this->warehouseUser->can('Delete:Invoice'));
    }

    public function test_operator_can_only_view_invoices(): void
    {
        $this->assertTrue($this->operator->can('ViewAny:Invoice'));
        $this->assertTrue($this->operator->can('View:Invoice'));
        $this->assertFalse($this->operator->can('Create:Invoice'));
        $this->assertFalse($this->operator->can('Update:Invoice'));
        $this->assertFalse($this->operator->can('Delete:Invoice'));
    }

    // ═══════════════════════════════════════════════════════════════
    //  Scoping por bodega vía WarehouseScope::apply
    // ═══════════════════════════════════════════════════════════════

    public function test_warehouse_user_query_filters_invoices_by_their_warehouse(): void
    {
        $this->actingAs($this->warehouseUser);

        $manifestOAC = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $manifestOAS = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAS->id]);

        $visible = Invoice::factory()->create([
            'manifest_id' => $manifestOAC->id,
            'warehouse_id' => $this->warehouseOAC->id,
        ]);

        $hidden = Invoice::factory()->create([
            'manifest_id' => $manifestOAS->id,
            'warehouse_id' => $this->warehouseOAS->id,
        ]);

        $visibleIds = InvoiceResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($visible->id, $visibleIds);
        $this->assertNotContains($hidden->id, $visibleIds);
    }

    public function test_super_admin_query_returns_all_invoices_across_warehouses(): void
    {
        $this->actingAs($this->superAdmin);

        $manifestOAC = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $manifestOAS = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAS->id]);

        $oac = Invoice::factory()->create([
            'manifest_id' => $manifestOAC->id,
            'warehouse_id' => $this->warehouseOAC->id,
        ]);
        $oas = Invoice::factory()->create([
            'manifest_id' => $manifestOAS->id,
            'warehouse_id' => $this->warehouseOAS->id,
        ]);

        $visibleIds = InvoiceResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($oac->id, $visibleIds);
        $this->assertContains($oas->id, $visibleIds);
    }

    // ═══════════════════════════════════════════════════════════════
    //  SoftDeletingScope removido — decisión explícita del Resource
    // ═══════════════════════════════════════════════════════════════

    public function test_invoice_resource_query_includes_soft_deleted_invoices(): void
    {
        // El Resource llama withoutGlobalScopes([SoftDeletingScope::class])
        // para que admins puedan investigar facturas borradas. Este test
        // congela ese contrato — si alguien remueve el withoutGlobalScopes,
        // este test rompe.
        $this->actingAs($this->superAdmin);

        $manifest = Manifest::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);
        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->warehouseOAC->id,
        ]);
        $invoice->delete();

        $this->assertNotNull($invoice->fresh()->deleted_at);

        $visibleIds = InvoiceResource::getEloquentQuery()->pluck('id')->toArray();

        $this->assertContains($invoice->id, $visibleIds);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Configuración del Resource — navegación oculta
    // ═══════════════════════════════════════════════════════════════

    public function test_invoice_resource_is_hidden_from_navigation(): void
    {
        // Las facturas se acceden dentro de ManifestResource (RelationManager).
        // Si alguien activa la navegación por accidente, este test rompe.
        $reflection = new \ReflectionClass(InvoiceResource::class);
        $property = $reflection->getProperty('shouldRegisterNavigation');
        $property->setAccessible(true);

        $this->assertFalse($property->getValue());
    }

    public function test_invoice_resource_has_no_pages(): void
    {
        // InvoiceResource existe solo para Shield — no expone pages propios.
        // Si alguien las agrega sin pensar, este test alerta.
        $this->assertSame([], InvoiceResource::getPages());
    }
}
