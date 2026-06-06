<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use App\Models\Warehouse;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TestUsersSeeder;
use Database\Seeders\WarehouseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests del TestUsersSeeder.
 *
 * Cubrimos:
 *  - Crea exactamente 10 usuarios (1 admin + 9 de bodega)
 *  - Jerarquía created_by: admin→super_admin, bodega→admin
 *  - warehouse_id correcto por código de bodega
 *  - Rol correcto asignado
 *  - Password hasheable (cast 'hashed' del modelo)
 *  - Idempotencia: 2 corridas no duplican
 *  - Cada usuario puede acceder al panel (canAccessPanel())
 *  - Aborta gracefully si faltan dependencias (super_admin o bodegas)
 */
class TestUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Filament necesita un panel registrado para los tests que verifiquen acceso.
        // En entornos de testing Filament usa el panel registrado por el AppProvider.
    }

    private function seedDependencies(): void
    {
        $this->seed([
            RoleSeeder::class,
            WarehouseSeeder::class,
        ]);

        // El super_admin antes lo creaba AdminUserSeeder. Ahora ese
        // seeder está deprecado — el bootstrap lo crea tipeando email
        // y password en runtime. Para los tests del TestUsersSeeder
        // basta con un super_admin pelado con un rol asignado.
        $superAdmin = User::factory()->create([
            'name' => 'Super Admin (test)',
            'email' => 'superadmin-test@hozana.local',
            'password' => Hash::make('passwordsuperseguro'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $superAdmin->assignRole(Utils::getSuperAdminName());
    }

    public function test_creates_exactly_ten_users_with_correct_total(): void
    {
        $this->seedDependencies();
        $usersBefore = User::query()->count(); // 1 super_admin

        $this->seed(TestUsersSeeder::class);

        $this->assertSame(
            $usersBefore + 10,
            User::query()->count(),
            'Debe crear 10 usuarios: 1 admin OPL + 3 bodegas × 3 roles.'
        );
    }

    public function test_admin_opl_alianza_is_created_as_child_of_super_admin(): void
    {
        $this->seedDependencies();
        $this->seed(TestUsersSeeder::class);

        $admin = User::query()->where('email', 'oplalianza@gmail.com')->first();
        $this->assertNotNull($admin);

        $superAdmin = User::query()
            ->role(Utils::getSuperAdminName())
            ->first();

        $this->assertSame($superAdmin->id, $admin->created_by);
        $this->assertNull($admin->warehouse_id, 'El admin es global, sin warehouse_id.');
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->isGlobalUser());
        $this->assertTrue($admin->is_active);
    }

    public function test_warehouse_users_are_children_of_admin(): void
    {
        $this->seedDependencies();
        $this->seed(TestUsersSeeder::class);

        $admin = User::query()->where('email', 'oplalianza@gmail.com')->first();

        $warehouseUsers = User::query()
            ->whereIn('email', [
                'encargadoOAC@gmail.com',
                'operadorOAC@gmail.com',
                'financeOAC@gmail.com',
                'encargadoOAS@gmail.com',
                'operadorOAS@gmail.com',
                'financeOAS@gmail.com',
                'encargadoOAO@gmail.com',
                'operadorOAO@gmail.com',
                'financeOAO@gmail.com',
            ])
            ->get();

        $this->assertCount(9, $warehouseUsers);

        foreach ($warehouseUsers as $user) {
            $this->assertSame($admin->id, $user->created_by);
            $this->assertNotNull($user->warehouse_id);
            $this->assertTrue($user->isWarehouseUser());
        }
    }

    public function test_each_warehouse_user_is_linked_to_correct_warehouse(): void
    {
        $this->seedDependencies();
        $this->seed(TestUsersSeeder::class);

        $warehouseByCode = Warehouse::query()
            ->whereIn('code', ['OAC', 'OAS', 'OAO'])
            ->get()
            ->keyBy('code');

        foreach (['OAC', 'OAS', 'OAO'] as $code) {
            foreach (['encargado', 'operador', 'finance'] as $role) {
                $user = User::query()
                    ->where('email', "{$role}{$code}@gmail.com")
                    ->first();

                $this->assertNotNull($user, "Falta el usuario {$role}{$code}@gmail.com");
                $this->assertSame(
                    $warehouseByCode->get($code)->id,
                    $user->warehouse_id,
                    "El usuario {$role}{$code} debería pertenecer a la bodega {$code}."
                );
                $this->assertTrue(
                    $user->hasRole($role),
                    "El usuario {$role}{$code} debería tener el rol {$role}."
                );
            }
        }
    }

    public function test_password_is_hashed_using_model_cast(): void
    {
        $this->seedDependencies();
        $this->seed(TestUsersSeeder::class);

        $user = User::query()->where('email', 'encargadoOAC@gmail.com')->first();

        // No debe quedar plaintext
        $this->assertNotSame('Hozana@2026', $user->getAttributes()['password']);

        // Debe matchear contra el plaintext esperado
        $this->assertTrue(
            Hash::check('Hozana@2026', $user->password),
            'El password seedeado debería verificar contra Hash::check.'
        );
    }

    public function test_password_can_be_overridden_via_env(): void
    {
        // putenv() NO impacta env() de Laravel — Laravel lee desde el
        // Dotenv repository (Env::getRepository), no desde getenv().
        // Hay que usar el bridge oficial para que env() vea el cambio.
        Env::getRepository()->set('TEST_USER_PASSWORD', 'PasswordCustomQA123!');

        try {
            $this->seedDependencies();
            $this->seed(TestUsersSeeder::class);

            $user = User::query()->where('email', 'operadorOAS@gmail.com')->first();

            $this->assertTrue(
                Hash::check('PasswordCustomQA123!', $user->password),
                'El password debería leerse del env TEST_USER_PASSWORD.'
            );
        } finally {
            Env::getRepository()->clear('TEST_USER_PASSWORD');
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedDependencies();

        $this->seed(TestUsersSeeder::class);
        $firstCount = User::query()->count();

        $this->seed(TestUsersSeeder::class);
        $secondCount = User::query()->count();

        $this->assertSame(
            $firstCount,
            $secondCount,
            'Re-ejecutar el seeder no debe duplicar usuarios.'
        );
    }

    public function test_aborts_gracefully_if_super_admin_missing(): void
    {
        // Solo bodegas, sin super_admin
        $this->seed(WarehouseSeeder::class);

        $this->seed(TestUsersSeeder::class);

        // No debe crear usuarios sin la raíz de la jerarquía
        $this->assertSame(0, User::query()->count());
    }

    public function test_aborts_gracefully_if_warehouses_missing(): void
    {
        // Roles + super_admin pero sin bodegas
        $this->seed(RoleSeeder::class);

        $superAdmin = User::factory()->create([
            'name' => 'Super Admin (test)',
            'email' => 'superadmin-test@hozana.local',
            'password' => Hash::make('passwordsuperseguro'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $superAdmin->assignRole(Utils::getSuperAdminName());

        $usersBefore = User::query()->count(); // 1 super_admin

        $this->seed(TestUsersSeeder::class);

        $this->assertSame(
            $usersBefore,
            User::query()->count(),
            'Sin bodegas, el seeder no debe crear los usuarios de bodega.'
        );
    }

    public function test_each_user_can_access_filament_panel(): void
    {
        $this->seedDependencies();
        $this->seed(TestUsersSeeder::class);

        $panel = Filament::getPanel('admin');

        $emails = [
            'oplalianza@gmail.com',
            'encargadoOAC@gmail.com', 'operadorOAC@gmail.com', 'financeOAC@gmail.com',
            'encargadoOAS@gmail.com', 'operadorOAS@gmail.com', 'financeOAS@gmail.com',
            'encargadoOAO@gmail.com', 'operadorOAO@gmail.com', 'financeOAO@gmail.com',
        ];

        foreach ($emails as $email) {
            $user = User::query()->where('email', $email)->first();

            $this->assertTrue(
                $user->canAccessPanel($panel),
                "El usuario {$email} debería poder acceder al panel admin."
            );
        }
    }
}
