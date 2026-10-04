<?php

namespace Tests\Feature\Edt;

use App\Models\Edt\EdtSupplier;
use App\Models\User;
use App\Policies\Edt\EdtSupplierPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * EdtSupplierPolicy: cada acción depende solo de su permiso Shield.
 * Los proveedores del EDT son globales, no hay filtro por bodega.
 */
class EdtSupplierPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['ViewAny', 'View', 'Create', 'Update', 'Delete'];

    private EdtSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ACTIONS as $action) {
            Permission::create(['name' => "{$action}:EdtSupplier", 'guard_name' => 'web']);
        }

        $this->supplier = EdtSupplier::factory()->create();
    }

    public function test_laravel_descubre_la_policy_en_el_namespace_edt(): void
    {
        $this->assertInstanceOf(EdtSupplierPolicy::class, Gate::getPolicyFor(EdtSupplier::class));
    }

    public function test_con_todos_los_permisos_puede_todo(): void
    {
        $user = $this->userWith(array_map(fn ($a) => "{$a}:EdtSupplier", self::ACTIONS));

        $this->assertTrue($user->can('viewAny', EdtSupplier::class));
        $this->assertTrue($user->can('view', $this->supplier));
        $this->assertTrue($user->can('create', EdtSupplier::class));
        $this->assertTrue($user->can('update', $this->supplier));
        $this->assertTrue($user->can('delete', $this->supplier));
    }

    public function test_sin_permisos_no_puede_nada(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->can('viewAny', EdtSupplier::class));
        $this->assertFalse($user->can('view', $this->supplier));
        $this->assertFalse($user->can('create', EdtSupplier::class));
        $this->assertFalse($user->can('update', $this->supplier));
        $this->assertFalse($user->can('delete', $this->supplier));
    }

    public function test_solo_lectura_ve_pero_no_crea_ni_edita_ni_borra(): void
    {
        $user = $this->userWith(['ViewAny:EdtSupplier', 'View:EdtSupplier']);

        $this->assertTrue($user->can('viewAny', EdtSupplier::class));
        $this->assertTrue($user->can('view', $this->supplier));
        $this->assertFalse($user->can('create', EdtSupplier::class));
        $this->assertFalse($user->can('update', $this->supplier));
        $this->assertFalse($user->can('delete', $this->supplier));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $role = Role::create(['name' => 'rol-'.uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
