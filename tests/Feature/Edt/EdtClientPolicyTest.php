<?php

namespace Tests\Feature\Edt;

use App\Models\Edt\EdtClient;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\EdtPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * EdtClientPolicy: permiso Shield + aislamiento por bodega.
 */
class EdtClientPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $oac;

    private Warehouse $oas;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'encargado'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
        $this->seed(EdtPermissionSeeder::class);

        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();
    }

    public function test_el_admin_global_puede_todo_en_cualquier_bodega(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = EdtClient::factory()->create(['warehouse_id' => $this->oas->id]);

        $this->assertTrue($admin->can('viewAny', EdtClient::class));
        $this->assertTrue($admin->can('create', EdtClient::class));
        $this->assertTrue($admin->can('view', $client));
        $this->assertTrue($admin->can('update', $client));
        $this->assertTrue($admin->can('delete', $client));
    }

    public function test_un_usuario_de_bodega_solo_toca_clientes_de_su_bodega(): void
    {
        $user = $this->warehouseUserWithAllPermissions($this->oac);
        $own = EdtClient::factory()->create(['warehouse_id' => $this->oac->id]);
        $other = EdtClient::factory()->create(['warehouse_id' => $this->oas->id]);

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue($user->can($ability, $own), "{$ability} en su bodega");
            $this->assertFalse($user->can($ability, $other), "{$ability} en otra bodega");
        }
    }

    public function test_un_usuario_con_dos_bodegas_ve_las_dos(): void
    {
        $user = $this->warehouseUserWithAllPermissions([$this->oac, $this->oas]);

        $this->assertTrue($user->can('view', EdtClient::factory()->create(['warehouse_id' => $this->oac->id])));
        $this->assertTrue($user->can('view', EdtClient::factory()->create(['warehouse_id' => $this->oas->id])));
    }

    public function test_sin_permiso_no_puede_nada_aunque_sea_de_su_bodega(): void
    {
        $user = User::factory()->forWarehouse($this->oac)->create();
        $user->assignRole('encargado');
        $client = EdtClient::factory()->create(['warehouse_id' => $this->oac->id]);

        $this->assertFalse($user->can('viewAny', EdtClient::class));
        $this->assertFalse($user->can('create', EdtClient::class));
        $this->assertFalse($user->can('view', $client));
        $this->assertFalse($user->can('update', $client));
        $this->assertFalse($user->can('delete', $client));
    }

    /**
     * @param  Warehouse|array<int, Warehouse>  $warehouses
     */
    private function warehouseUserWithAllPermissions(Warehouse|array $warehouses): User
    {
        $user = User::factory()->forWarehouse($warehouses)->create();
        $user->givePermissionTo(array_map(
            fn (string $action): string => "{$action}:EdtClient",
            EdtPermissionSeeder::PERMISSIONS_BY_MODEL['EdtClient'],
        ));

        return $user;
    }
}
