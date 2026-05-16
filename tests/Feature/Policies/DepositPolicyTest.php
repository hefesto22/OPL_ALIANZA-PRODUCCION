<?php

namespace Tests\Feature\Policies;

use App\Models\Deposit;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\DepositPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aislamiento por bodega de DepositPolicy.
 *
 * Caso especial: Deposit NO tiene warehouse_id propio — la pertenencia a
 * bodega se deriva de manifest.warehouse_id. El trait usa la variante
 * userOwnsRecordViaRelation() para esto.
 *
 * Edge case crítico cubierto: depósito cuyo manifiesto fue soft-deleted.
 * En ese caso la relación retorna null y el usuario de bodega no puede
 * verificar contexto → denegado. El admin global pasa siempre.
 */
class DepositPolicyTest extends TestCase
{
    use RefreshDatabase;

    private DepositPolicy $policy;

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
            Permission::create(['name' => "{$action}:Deposit", 'guard_name' => 'web']);
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

        $this->policy = new DepositPolicy;
    }

    private function depositFor(Warehouse $warehouse): Deposit
    {
        $manifest = Manifest::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $warehouse->id,
        ]);

        return Deposit::factory()->create([
            'manifest_id' => $manifest->id,
        ]);
    }

    public function test_usuario_global_puede_ver_deposito_de_cualquier_bodega(): void
    {
        $this->assertTrue($this->policy->view($this->globalUser, $this->depositFor($this->oac)));
        $this->assertTrue($this->policy->view($this->globalUser, $this->depositFor($this->oas)));
    }

    public function test_usuario_de_bodega_puede_ver_deposito_de_su_bodega(): void
    {
        $this->assertTrue($this->policy->view($this->oacUser, $this->depositFor($this->oac)));
    }

    public function test_usuario_de_bodega_no_puede_ver_deposito_de_otra_bodega(): void
    {
        $depositoOas = $this->depositFor($this->oas);

        $this->assertFalse(
            $this->policy->view($this->oacUser, $depositoOas),
            'Operador OAC no debería poder ver depósitos vinculados a manifiestos de OAS'
        );
    }

    public function test_usuario_de_bodega_no_puede_ver_deposito_cuando_manifest_esta_borrado(): void
    {
        // Crear depósito de OAC y borrar su manifiesto (soft delete)
        $manifest = Manifest::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->oac->id,
        ]);
        $deposit = Deposit::factory()->create(['manifest_id' => $manifest->id]);

        $manifest->delete();

        // Eloquent no trae soft-deleted en relaciones por default →
        // $deposit->manifest viene null. El usuario de bodega no puede
        // verificar contexto y se le deniega defensivamente.
        $this->assertFalse(
            $this->policy->view($this->oacUser, $deposit->fresh()),
            'Sin contexto verificable del manifiesto, no podemos asegurar pertenencia → denegar'
        );

        // El admin global sigue pasando — no necesita verificar contexto
        // porque su check retorna true antes de tocar la relación.
        $this->assertTrue($this->policy->view($this->globalUser, $deposit->fresh()));
    }

    public function test_usuario_sin_permiso_no_puede_ver_aunque_sea_su_bodega(): void
    {
        $depositoOac = $this->depositFor($this->oac);

        $this->assertFalse($this->policy->view($this->userSinPermisos, $depositoOac));
    }

    public function test_aislamiento_aplica_a_todas_las_acciones_con_modelo(): void
    {
        $depositoOas = $this->depositFor($this->oas);

        $this->assertFalse($this->policy->update($this->oacUser, $depositoOas));
        $this->assertFalse($this->policy->delete($this->oacUser, $depositoOas));
        $this->assertFalse($this->policy->restore($this->oacUser, $depositoOas));
        $this->assertFalse($this->policy->forceDelete($this->oacUser, $depositoOas));
        $this->assertFalse($this->policy->replicate($this->oacUser, $depositoOas));

        $this->assertTrue($this->policy->update($this->globalUser, $depositoOas));
        $this->assertTrue($this->policy->delete($this->globalUser, $depositoOas));
    }

    public function test_metodos_sin_modelo_no_aplican_aislamiento(): void
    {
        $this->assertTrue($this->policy->viewAny($this->oacUser));
        $this->assertTrue($this->policy->create($this->oacUser));
        $this->assertTrue($this->policy->reorder($this->oacUser));

        $this->assertFalse($this->policy->viewAny($this->userSinPermisos));
    }

    public function test_update_y_delete_bloqueados_en_deposito_cancelado(): void
    {
        // Un depósito cancelado queda inmutable: ni edit ni cancel adicional.
        // La Policy es la última línea de defensa — bloquea aunque la UI
        // muestre el botón (lo que no debería pasar) o el usuario entre
        // por URL directa /deposits/{id}/edit.
        $deposit = $this->depositFor($this->oac);
        $deposit->update([
            'cancelled_at' => now(),
            'cancelled_by' => $this->globalUser->id,
            'cancellation_reason' => 'Cancelación de prueba xxx',
        ]);
        $deposit->refresh();

        $this->assertTrue($deposit->isCancelled(), 'Setup: el deposit debe estar cancelado');

        // Update bloqueado tanto para usuario global como de bodega.
        $this->assertFalse(
            $this->policy->update($this->globalUser, $deposit),
            'Update sobre cancelado: bloqueado aunque el user tenga permisos globales'
        );
        $this->assertFalse(
            $this->policy->update($this->oacUser, $deposit),
            'Update sobre cancelado: bloqueado para user de bodega'
        );

        // Delete (= cancelar otra vez): bloqueado.
        $this->assertFalse(
            $this->policy->delete($this->globalUser, $deposit),
            'Delete sobre cancelado: el cancelado no se puede re-cancelar'
        );

        // ForceDelete sigue disponible — super_admin puede hard-deletear
        // un cancelado para depurar datos (caso excepcional).
        $this->assertTrue(
            $this->policy->forceDelete($this->globalUser, $deposit),
            'ForceDelete sobre cancelado: sigue permitido (super_admin override)'
        );
    }
}
