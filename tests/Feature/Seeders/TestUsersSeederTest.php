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
 *  - Crea exactamente 12 usuarios de bodega (3 roles × 4 bodegas)
 *  - Jerarquía created_by: todos hijos del super_admin
 *  - warehouse_id correcto por código de bodega
 *  - Email derivado del slug de ciudad ({rol}.{ciudad}@gmail.com)
 *  - Rol correcto asignado
 *  - Password hasheable (cast 'hashed' del modelo) + override por env
 *  - Idempotencia: 2 corridas no duplican
 *  - Cada usuario puede acceder al panel (canAccessPanel())
 *  - Aborta gracefully si faltan dependencias (super_admin o bodegas)
 */
class TestUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Emails esperados de los 12 usuarios de bodega.
     *
     * @var array<int, string>
     */
    private const EXPECTED_EMAILS = [
        'encargado.copan@gmail.com', 'operador.copan@gmail.com', 'finance.copan@gmail.com',
        'encargado.santabarbara@gmail.com', 'operador.santabarbara@gmail.com', 'finance.santabarbara@gmail.com',
        'encargado.ocotepeque@gmail.com', 'operador.ocotepeque@gmail.com', 'finance.ocotepeque@gmail.com',
        'encargado.intibuca@gmail.com', 'operador.intibuca@gmail.com', 'finance.intibuca@gmail.com',
    ];

    /**
     * Mapa código de bodega → slug de ciudad usado en el email.
     *
     * @var array<string, string>
     */
    private const WAREHOUSE_SLUG = [
        'OAC' => 'copan',
        'OAS' => 'santabarbara',
        'OAO' => 'ocotepeque',
        'OAI' => 'intibuca',
    ];

    private function seedDependencies(): void
    {
        $this->seed([
            RoleSeeder::class,
            WarehouseSeeder::class,
        ]);

        // El super_admin lo crea el bootstrap tipeando credenciales en
        // runtime. Para los tests del TestUsersSeeder basta con un
        // super_admin pelado con su rol asignado: es la raíz de la jerarquía.
        $superAdmin = User::factory()->create([
            'name' => 'Super Admin (test)',
            'email' => 'superadmin-test@hozana.local',
            'password' => Hash::make('passwordsuperseguro'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $superAdmin->assignRole(Utils::getSuperAdminName());
    }

    public function test_creates_exactly_twelve_warehouse_users(): void
    {
        $this->seedDependencies();
        $usersBefore = User::query()->count(); // 1 super_admin

        $this->seed(TestUsersSeeder::class);

        $this->assertSame(
            $usersBefore + 12,
            User::query()->count(),
            'Debe crear 12 usuarios: 3 roles × 4 bodegas.'
        );
    }

    public function test_warehouse_users_are_children_of_super_admin(): void
    {
        $this->seedDependencies();
        $this->seed(TestUsersSeeder::class);

        $superAdmin = User::query()
            ->role(Utils::getSuperAdminName())
            ->first();

        $warehouseUsers = User::query()
            ->whereIn('email', self::EXPECTED_EMAILS)
            ->get();

        $this->assertCount(12, $warehouseUsers);

        foreach ($warehouseUsers as $user) {
            $this->assertSame($superAdmin->id, $user->created_by);
            $this->assertNotEmpty($user->warehouseIds());
            $this->assertTrue($user->isWarehouseUser());
            $this->assertTrue($user->is_active);
        }
    }

    public function test_no_admin_role_user_is_seeded(): void
    {
        $this->seedDependencies();
        $this->seed(TestUsersSeeder::class);

        // El rol 'admin' existe pero el seeder NO crea un usuario con él.
        $this->assertSame(
            0,
            User::query()->role('admin')->count(),
            'El seeder no debe crear usuarios con rol admin (se asignan desde el panel).'
        );
    }

    public function test_each_warehouse_user_is_linked_to_correct_warehouse(): void
    {
        $this->seedDependencies();
        $this->seed(TestUsersSeeder::class);

        $warehouseByCode = Warehouse::query()
            ->whereIn('code', array_keys(self::WAREHOUSE_SLUG))
            ->get()
            ->keyBy('code');

        foreach (self::WAREHOUSE_SLUG as $code => $slug) {
            foreach (['encargado', 'operador', 'finance'] as $role) {
                $email = "{$role}.{$slug}@gmail.com";
                $user = User::query()->where('email', $email)->first();

                $this->assertNotNull($user, "Falta el usuario {$email}");
                $this->assertContains(
                    $warehouseByCode->get($code)->id,
                    $user->warehouseIds(),
                    "El usuario {$email} debería pertenecer a la bodega {$code}."
                );
                $this->assertTrue(
                    $user->hasRole($role),
                    "El usuario {$email} debería tener el rol {$role}."
                );
            }
        }
    }

    public function test_password_is_hashed_using_model_cast(): void
    {
        // Fijamos el password en el test para no depender del .env local
        // del desarrollador (que puede tener TEST_USER_PASSWORD definido).
        // Así el test es determinista en cualquier máquina/CI.
        Env::getRepository()->set('TEST_USER_PASSWORD', 'Hozana@2026');

        try {
            $this->seedDependencies();
            $this->seed(TestUsersSeeder::class);

            $user = User::query()->where('email', 'encargado.copan@gmail.com')->first();

            // No debe quedar plaintext
            $this->assertNotSame('Hozana@2026', $user->getAttributes()['password']);

            // Debe matchear contra el plaintext vía el cast 'hashed' del modelo
            $this->assertTrue(
                Hash::check('Hozana@2026', $user->password),
                'El password seedeado debería verificar contra Hash::check.'
            );
        } finally {
            Env::getRepository()->clear('TEST_USER_PASSWORD');
        }
    }

    public function test_password_can_be_overridden_via_env(): void
    {
        // putenv() NO impacta env() de Laravel — hay que usar el bridge oficial.
        Env::getRepository()->set('TEST_USER_PASSWORD', '12345678');

        try {
            $this->seedDependencies();
            $this->seed(TestUsersSeeder::class);

            $user = User::query()->where('email', 'operador.santabarbara@gmail.com')->first();

            $this->assertTrue(
                Hash::check('12345678', $user->password),
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

        foreach (self::EXPECTED_EMAILS as $email) {
            $user = User::query()->where('email', $email)->first();

            $this->assertTrue(
                $user->canAccessPanel($panel),
                "El usuario {$email} debería poder acceder al panel admin."
            );
        }
    }
}
