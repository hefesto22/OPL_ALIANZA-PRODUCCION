<?php

namespace Tests\Feature\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\InvoicePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aislamiento por bodega de InvoicePolicy.
 *
 * Garantiza que un operador de una bodega NO pueda ver/editar/borrar
 * facturas de otra bodega aun cuando tenga el permiso View/Update/Delete:Invoice.
 *
 * Edge case crítico cubierto: facturas pending_warehouse (warehouse_id = NULL).
 * Son facturas que aún no se han asignado a una bodega — solo el usuario
 * global puede manipularlas. Es ahí donde, históricamente, se ha producido
 * la fuga cross-bodega cuando un Resource olvida filtrar.
 */
class InvoicePolicyTest extends TestCase
{
    use RefreshDatabase;

    private InvoicePolicy $policy;

    private Warehouse $oac;

    private Warehouse $oas;

    private User $globalUser;

    private User $oacUser;

    private User $userSinPermisos;

    protected function setUp(): void
    {
        parent::setUp();

        // Permisos canónicos generados por Filament Shield para el recurso.
        // Permisos canónicos generados por Filament Shield para el recurso.
        // Incluye los *Any de Filament 4 (ForceDeleteAny, RestoreAny) y Reorder.
        $acciones = [
            'ViewAny', 'View', 'Create', 'Update', 'Delete',
            'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny',
            'Replicate', 'Reorder',
        ];
        foreach ($acciones as $action) {
            Permission::create(['name' => "{$action}:Invoice", 'guard_name' => 'web']);
        }

        $rolFull = Role::create(['name' => 'tester-full', 'guard_name' => 'web']);
        $rolFull->givePermissionTo(Permission::all());

        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();

        $this->globalUser = User::factory()->create(['warehouse_id' => null]);
        $this->globalUser->assignRole($rolFull);

        $this->oacUser = User::factory()->create(['warehouse_id' => $this->oac->id]);
        $this->oacUser->assignRole($rolFull);

        $this->userSinPermisos = User::factory()->create(['warehouse_id' => $this->oac->id]);

        $this->policy = new InvoicePolicy;
    }

    private function invoiceFor(Warehouse $warehouse): Invoice
    {
        return Invoice::factory()->create(['warehouse_id' => $warehouse->id]);
    }

    public function test_usuario_global_puede_ver_factura_de_cualquier_bodega(): void
    {
        $facturaOac = $this->invoiceFor($this->oac);
        $facturaOas = $this->invoiceFor($this->oas);

        $this->assertTrue($this->policy->view($this->globalUser, $facturaOac));
        $this->assertTrue($this->policy->view($this->globalUser, $facturaOas));
    }

    public function test_usuario_de_bodega_puede_ver_factura_de_su_bodega(): void
    {
        $facturaOac = $this->invoiceFor($this->oac);

        $this->assertTrue($this->policy->view($this->oacUser, $facturaOac));
    }

    public function test_usuario_de_bodega_no_puede_ver_factura_de_otra_bodega(): void
    {
        $facturaOas = $this->invoiceFor($this->oas);

        $this->assertFalse(
            $this->policy->view($this->oacUser, $facturaOas),
            'Operador OAC no debería poder ver facturas de OAS por URL directa'
        );
    }

    public function test_usuario_de_bodega_no_puede_ver_factura_pending_warehouse(): void
    {
        // Factura sin asignar a bodega (warehouse_id = NULL).
        // Sólo el usuario global debe poder verla.
        $facturaSinAsignar = Invoice::factory()->pendingWarehouse()->create();

        $this->assertFalse($this->policy->view($this->oacUser, $facturaSinAsignar));
        $this->assertTrue($this->policy->view($this->globalUser, $facturaSinAsignar));
    }

    public function test_usuario_sin_permiso_no_puede_ver_aunque_sea_su_bodega(): void
    {
        $facturaOac = $this->invoiceFor($this->oac);

        $this->assertFalse(
            $this->policy->view($this->userSinPermisos, $facturaOac),
            'Sin el permiso View:Invoice, el chequeo de bodega ni siquiera importa'
        );
    }

    public function test_aislamiento_aplica_a_update_delete_restore_forcedelete_replicate(): void
    {
        $facturaOas = $this->invoiceFor($this->oas);

        // Operador de OAC bloqueado en toda acción que recibe el modelo
        $this->assertFalse($this->policy->update($this->oacUser, $facturaOas));
        $this->assertFalse($this->policy->delete($this->oacUser, $facturaOas));
        $this->assertFalse($this->policy->restore($this->oacUser, $facturaOas));
        $this->assertFalse($this->policy->forceDelete($this->oacUser, $facturaOas));
        $this->assertFalse($this->policy->replicate($this->oacUser, $facturaOas));

        // Global pasa en todas
        $this->assertTrue($this->policy->update($this->globalUser, $facturaOas));
        $this->assertTrue($this->policy->delete($this->globalUser, $facturaOas));
        $this->assertTrue($this->policy->restore($this->globalUser, $facturaOas));
        $this->assertTrue($this->policy->forceDelete($this->globalUser, $facturaOas));
        $this->assertTrue($this->policy->replicate($this->globalUser, $facturaOas));
    }

    public function test_metodos_sin_modelo_no_aplican_aislamiento(): void
    {
        // viewAny, create, *Any, reorder no reciben modelo — sólo dependen del
        // permiso. La capa de query (WarehouseScope) protege el listado.
        $this->assertTrue($this->policy->viewAny($this->oacUser));
        $this->assertTrue($this->policy->create($this->oacUser));
        $this->assertTrue($this->policy->forceDeleteAny($this->oacUser));
        $this->assertTrue($this->policy->restoreAny($this->oacUser));
        $this->assertTrue($this->policy->reorder($this->oacUser));

        // Sin permiso siguen denegados
        $this->assertFalse($this->policy->viewAny($this->userSinPermisos));
        $this->assertFalse($this->policy->create($this->userSinPermisos));
    }
}
