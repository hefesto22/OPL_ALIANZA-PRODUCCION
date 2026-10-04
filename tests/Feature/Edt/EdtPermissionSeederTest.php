<?php

namespace Tests\Feature\Edt;

use App\Models\Edt\EdtSupplier;
use App\Models\User;
use App\Policies\Edt\EdtSupplierPolicy;
use Database\Seeders\EdtPermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * EdtPermissionSeeder: el delta de permisos del EDT para entornos que ya
 * existen (local, pruebas y producción).
 *
 * Lo crítico: es ADITIVO. En producción los roles tienen ajustes hechos a
 * mano desde Shield; este seeder no puede quitar ninguno.
 */
class EdtPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'admin', 'encargado', 'operador', 'finance'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_crea_los_permisos_y_se_los_da_a_super_admin_y_admin(): void
    {
        $this->seed(EdtPermissionSeeder::class);

        foreach (['super_admin', 'admin'] as $roleName) {
            $role = Role::findByName($roleName);

            foreach (EdtPermissionSeeder::permissionNames() as $permission) {
                $this->assertTrue(
                    $role->hasPermissionTo($permission),
                    "El rol {$roleName} debería tener {$permission}."
                );
            }
        }
    }

    public function test_no_le_da_permisos_del_edt_a_los_roles_de_bodega(): void
    {
        $this->seed(EdtPermissionSeeder::class);

        foreach (['encargado', 'operador', 'finance'] as $roleName) {
            $this->assertSame(0, Role::findByName($roleName)->permissions()->count());
        }
    }

    public function test_es_aditivo_no_quita_permisos_que_el_rol_ya_tenia(): void
    {
        // Simula un ajuste hecho a mano en producción desde Shield.
        Permission::create(['name' => 'Close:Manifest', 'guard_name' => 'web']);
        Role::findByName('admin')->givePermissionTo('Close:Manifest');

        $this->seed(EdtPermissionSeeder::class);

        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('Close:Manifest'));
    }

    public function test_es_idempotente(): void
    {
        $this->seed(EdtPermissionSeeder::class);
        $this->seed(EdtPermissionSeeder::class);

        $this->assertSame(
            count(EdtPermissionSeeder::permissionNames()),
            Permission::where('name', 'like', '%:Edt%')->count()
        );
        $this->assertSame(
            count(EdtPermissionSeeder::permissionNames()),
            Role::findByName('admin')->permissions()->count()
        );
    }

    public function test_no_falla_si_falta_un_rol(): void
    {
        Role::findByName('admin')->delete();

        $this->seed(EdtPermissionSeeder::class);

        $this->assertTrue(Role::findByName('super_admin')->hasPermissionTo('ViewAny:EdtSupplier'));
    }

    public function test_los_nombres_coinciden_con_la_policy(): void
    {
        // Si el seeder y la Policy usan nombres distintos, el admin tendría
        // "permisos" que no abren nada. Se prueba por el Gate, no por nombre.
        $this->seed(EdtPermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('admin');
        $supplier = EdtSupplier::factory()->create();

        $this->assertTrue($user->can('viewAny', EdtSupplier::class));
        $this->assertTrue($user->can('view', $supplier));
        $this->assertTrue($user->can('create', EdtSupplier::class));
        $this->assertTrue($user->can('update', $supplier));
        $this->assertTrue($user->can('delete', $supplier));
    }

    public function test_cada_permiso_corresponde_a_un_metodo_de_la_policy(): void
    {
        // Un permiso sin método en la Policy no protege nada; un método sin
        // permiso sembrado deja la acción cerrada para todos. Ambos casos
        // fallan acá. Los nombres siguen la convención de Shield
        // (Accion:Modelo en PascalCase), la misma que usa shield:generate.
        $policies = [
            'EdtSupplier' => EdtSupplierPolicy::class,
        ];

        $this->assertSame(array_keys(EdtPermissionSeeder::PERMISSIONS_BY_MODEL), array_keys($policies));

        foreach (EdtPermissionSeeder::PERMISSIONS_BY_MODEL as $model => $actions) {
            $policy = new ReflectionClass($policies[$model]);

            // Solo los métodos escritos en la Policy (no los del trait
            // HandlesAuthorization, que viven en otro archivo).
            $policyMethods = collect($policy->getMethods(ReflectionMethod::IS_PUBLIC))
                ->filter(fn (ReflectionMethod $method): bool => $method->getFileName() === $policy->getFileName())
                ->map(fn (ReflectionMethod $method): string => $method->getName())
                ->values()
                ->all();

            $this->assertEqualsCanonicalizing(
                array_map(fn (string $action): string => Str::camel($action), $actions),
                $policyMethods,
                "Los permisos de {$model} no coinciden con los métodos de su Policy."
            );
        }
    }

    public function test_coincide_con_la_matriz_del_admin_en_role_permission_seeder(): void
    {
        // Bootstrap desde cero: el admin recibe el EDT por la matriz. Tiene
        // que ser lo mismo que este seeder le da en los entornos existentes.
        $matrix = (new ReflectionClassConstant(RolePermissionSeeder::class, 'MATRIX'))->getValue();

        foreach (EdtPermissionSeeder::PERMISSIONS_BY_MODEL as $model => $actions) {
            $this->assertSame($actions, $matrix['admin'][$model] ?? null, "Matriz del admin para {$model}.");
        }
    }
}
