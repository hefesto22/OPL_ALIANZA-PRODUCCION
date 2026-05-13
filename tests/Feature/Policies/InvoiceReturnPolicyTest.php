<?php

namespace Tests\Feature\Policies;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\InvoiceReturnPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aislamiento por bodega de InvoiceReturnPolicy.
 *
 * Las devoluciones tienen warehouse_id directo (copiado de la factura al
 * momento de crearse). Un operador de OAC NO debe ver devoluciones de OAS
 * aunque tenga permiso View:InvoiceReturn.
 */
class InvoiceReturnPolicyTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceReturnPolicy $policy;

    private Supplier $supplier;

    private Warehouse $oac;

    private Warehouse $oas;

    private User $globalUser;

    private User $oacUser;

    private User $userSinPermisos;

    protected function setUp(): void
    {
        parent::setUp();

        $acciones = [
            'ViewAny', 'View', 'Create', 'Update', 'Delete',
            'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny',
            'Replicate', 'Reorder',
        ];
        foreach ($acciones as $action) {
            Permission::create(['name' => "{$action}:InvoiceReturn", 'guard_name' => 'web']);
        }

        $rolFull = Role::create(['name' => 'tester-full', 'guard_name' => 'web']);
        $rolFull->givePermissionTo(Permission::all());

        $this->supplier = Supplier::factory()->create();
        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();

        $this->globalUser = User::factory()->create(['warehouse_id' => null]);
        $this->globalUser->assignRole($rolFull);

        $this->oacUser = User::factory()->create(['warehouse_id' => $this->oac->id]);
        $this->oacUser->assignRole($rolFull);

        $this->userSinPermisos = User::factory()->create(['warehouse_id' => $this->oac->id]);

        $this->policy = new InvoiceReturnPolicy;
    }

    private function returnFor(Warehouse $warehouse): InvoiceReturn
    {
        $manifest = Manifest::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $warehouse->id,
        ]);

        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
        ]);

        return InvoiceReturn::factory()->create([
            'manifest_id' => $manifest->id,
            'invoice_id' => $invoice->id,
            'return_reason_id' => ReturnReason::factory(),
            'warehouse_id' => $warehouse->id,
        ]);
    }

    public function test_usuario_global_puede_ver_devolucion_de_cualquier_bodega(): void
    {
        $this->assertTrue($this->policy->view($this->globalUser, $this->returnFor($this->oac)));
        $this->assertTrue($this->policy->view($this->globalUser, $this->returnFor($this->oas)));
    }

    public function test_usuario_de_bodega_puede_ver_devolucion_de_su_bodega(): void
    {
        $this->assertTrue($this->policy->view($this->oacUser, $this->returnFor($this->oac)));
    }

    public function test_usuario_de_bodega_no_puede_ver_devolucion_de_otra_bodega(): void
    {
        $devolucionOas = $this->returnFor($this->oas);

        $this->assertFalse($this->policy->view($this->oacUser, $devolucionOas));
    }

    public function test_usuario_sin_permiso_no_puede_ver_aunque_sea_su_bodega(): void
    {
        $devolucionOac = $this->returnFor($this->oac);

        $this->assertFalse($this->policy->view($this->userSinPermisos, $devolucionOac));
    }

    public function test_aislamiento_aplica_a_todas_las_acciones_con_modelo(): void
    {
        $devolucionOas = $this->returnFor($this->oas);

        $this->assertFalse($this->policy->update($this->oacUser, $devolucionOas));
        $this->assertFalse($this->policy->delete($this->oacUser, $devolucionOas));
        $this->assertFalse($this->policy->restore($this->oacUser, $devolucionOas));
        $this->assertFalse($this->policy->forceDelete($this->oacUser, $devolucionOas));
        $this->assertFalse($this->policy->replicate($this->oacUser, $devolucionOas));

        $this->assertTrue($this->policy->update($this->globalUser, $devolucionOas));
        $this->assertTrue($this->policy->delete($this->globalUser, $devolucionOas));
    }

    public function test_metodos_sin_modelo_no_aplican_aislamiento(): void
    {
        $this->assertTrue($this->policy->viewAny($this->oacUser));
        $this->assertTrue($this->policy->create($this->oacUser));
        $this->assertTrue($this->policy->reorder($this->oacUser));

        $this->assertFalse($this->policy->viewAny($this->userSinPermisos));
    }
}
