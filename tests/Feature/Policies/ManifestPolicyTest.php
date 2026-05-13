<?php

namespace Tests\Feature\Policies;

use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\ManifestPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aislamiento por bodega de ManifestPolicy.
 *
 * Un manifiesto tiene warehouse_id directo (cada manifiesto pertenece a
 * exactamente una bodega). El test verifica que la Policy bloquea acceso
 * cross-bodega en todas las acciones que reciben el modelo.
 */
class ManifestPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ManifestPolicy $policy;

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
            Permission::create(['name' => "{$action}:Manifest", 'guard_name' => 'web']);
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

        $this->policy = new ManifestPolicy;
    }

    private function manifestFor(Warehouse $warehouse): Manifest
    {
        return Manifest::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $warehouse->id,
        ]);
    }

    public function test_usuario_global_puede_ver_manifiesto_de_cualquier_bodega(): void
    {
        $this->assertTrue($this->policy->view($this->globalUser, $this->manifestFor($this->oac)));
        $this->assertTrue($this->policy->view($this->globalUser, $this->manifestFor($this->oas)));
    }

    public function test_usuario_de_bodega_puede_ver_manifiesto_de_su_bodega(): void
    {
        $this->assertTrue($this->policy->view($this->oacUser, $this->manifestFor($this->oac)));
    }

    public function test_usuario_de_bodega_no_puede_ver_manifiesto_de_otra_bodega(): void
    {
        $manifiestoOas = $this->manifestFor($this->oas);

        $this->assertFalse($this->policy->view($this->oacUser, $manifiestoOas));
    }

    public function test_usuario_sin_permiso_no_puede_ver_aunque_sea_su_bodega(): void
    {
        $manifiestoOac = $this->manifestFor($this->oac);

        $this->assertFalse($this->policy->view($this->userSinPermisos, $manifiestoOac));
    }

    public function test_aislamiento_aplica_a_todas_las_acciones_con_modelo(): void
    {
        $manifiestoOas = $this->manifestFor($this->oas);

        $this->assertFalse($this->policy->update($this->oacUser, $manifiestoOas));
        $this->assertFalse($this->policy->delete($this->oacUser, $manifiestoOas));
        $this->assertFalse($this->policy->restore($this->oacUser, $manifiestoOas));
        $this->assertFalse($this->policy->forceDelete($this->oacUser, $manifiestoOas));
        $this->assertFalse($this->policy->replicate($this->oacUser, $manifiestoOas));

        $this->assertTrue($this->policy->update($this->globalUser, $manifiestoOas));
        $this->assertTrue($this->policy->delete($this->globalUser, $manifiestoOas));
        $this->assertTrue($this->policy->restore($this->globalUser, $manifiestoOas));
        $this->assertTrue($this->policy->forceDelete($this->globalUser, $manifiestoOas));
        $this->assertTrue($this->policy->replicate($this->globalUser, $manifiestoOas));
    }

    public function test_metodos_sin_modelo_no_aplican_aislamiento(): void
    {
        $this->assertTrue($this->policy->viewAny($this->oacUser));
        $this->assertTrue($this->policy->create($this->oacUser));
        $this->assertTrue($this->policy->reorder($this->oacUser));

        $this->assertFalse($this->policy->viewAny($this->userSinPermisos));
    }
}
