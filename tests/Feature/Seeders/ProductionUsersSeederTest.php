<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use App\Models\Warehouse;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\ProductionUsersSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests del ProductionUsersSeeder (equipo real de Hozana).
 *
 * Cubrimos:
 *  - Crea a Mayra (admin global) + 5 operativos, con created_by correcto.
 *  - Jerarquía de visibilidad: super_admin ve a todos; Mayra ve solo a los
 *    suyos y NO al super_admin (lo importante del pedido).
 *  - Roles dobles (operador + finance) para Keyli/Ana/Jovany.
 *  - Borrado DEFINITIVO de usuarios viejos, conservando super_admin + equipo.
 *  - Password '12345678' hasheado por el cast del modelo.
 *  - Idempotencia y aborto si falta el super_admin.
 */
class ProductionUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const TEAM_EMAILS = [
        'mayra@gmail.com',
        'sophia@gmail.com',
        'arli@gmail.com',
        'keyli@gmail.com',
        'ana@gmail.com',
        'jovany@gmail.com',
    ];

    private function seedDependencies(): User
    {
        $this->seed([
            RoleSeeder::class,
            WarehouseSeeder::class,
        ]);

        $superAdmin = User::factory()->create([
            'name' => 'Super Admin (test)',
            'email' => 'admin@gmail.com',
            'password' => Hash::make('passwordsuperseguro'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $superAdmin->assignRole(Utils::getSuperAdminName());

        return $superAdmin;
    }

    public function test_creates_mayra_and_five_operatives(): void
    {
        $this->seedDependencies();

        $this->seed(ProductionUsersSeeder::class);

        foreach (self::TEAM_EMAILS as $email) {
            $this->assertNotNull(
                User::query()->where('email', $email)->first(),
                "Falta el usuario {$email}"
            );
        }
    }

    public function test_mayra_is_global_admin_created_by_super_admin(): void
    {
        $superAdmin = $this->seedDependencies();
        $this->seed(ProductionUsersSeeder::class);

        $mayra = User::query()->where('email', 'mayra@gmail.com')->first();

        $this->assertTrue($mayra->hasRole('admin'));
        $this->assertSame($superAdmin->id, $mayra->created_by);
        $this->assertTrue($mayra->isGlobalUser(), 'Mayra debe ser global (sin bodega).');
        $this->assertEmpty($mayra->warehouseIds());
    }

    public function test_operatives_are_children_of_mayra_with_correct_warehouses(): void
    {
        $this->seedDependencies();
        $this->seed(ProductionUsersSeeder::class);

        $mayra = User::query()->where('email', 'mayra@gmail.com')->first();
        $warehouseByCode = Warehouse::query()->get()->keyBy('code');

        $expected = [
            'sophia@gmail.com' => 'OAC',
            'arli@gmail.com' => 'OAC',
            'keyli@gmail.com' => 'OAO',
            'ana@gmail.com' => 'OAS',
            'jovany@gmail.com' => 'OAI',
        ];

        foreach ($expected as $email => $code) {
            $user = User::query()->where('email', $email)->first();

            $this->assertSame($mayra->id, $user->created_by, "{$email} debe ser hijo de Mayra.");
            $this->assertContains(
                $warehouseByCode->get($code)->id,
                $user->warehouseIds(),
                "{$email} debe pertenecer a {$code}."
            );
        }
    }

    public function test_double_role_users_have_operador_and_finance(): void
    {
        $this->seedDependencies();
        $this->seed(ProductionUsersSeeder::class);

        foreach (['keyli@gmail.com', 'ana@gmail.com', 'jovany@gmail.com'] as $email) {
            $user = User::query()->where('email', $email)->first();
            $this->assertTrue($user->hasRole('operador'), "{$email} debe tener rol operador.");
            $this->assertTrue($user->hasRole('finance'), "{$email} debe tener rol finance.");
        }

        // Sophia (solo finance) y Arli (solo operador) NO deben tener el otro rol.
        $sophia = User::query()->where('email', 'sophia@gmail.com')->first();
        $this->assertTrue($sophia->hasRole('finance'));
        $this->assertFalse($sophia->hasRole('operador'));

        $arli = User::query()->where('email', 'arli@gmail.com')->first();
        $this->assertTrue($arli->hasRole('operador'));
        $this->assertFalse($arli->hasRole('finance'));
    }

    public function test_hierarchy_visibility_super_admin_sees_all_mayra_sees_only_her_team(): void
    {
        $superAdmin = $this->seedDependencies();
        $this->seed(ProductionUsersSeeder::class);

        $mayra = User::query()->where('email', 'mayra@gmail.com')->first();

        // super_admin ve a todos (7: él + Mayra + 5).
        $this->assertSame(7, User::query()->visibleTo($superAdmin)->count());

        // Mayra ve solo a sí misma + sus 5 → 6, y NUNCA al super_admin.
        $visibleToMayra = User::query()->visibleTo($mayra)->pluck('id');
        $this->assertCount(6, $visibleToMayra);
        $this->assertTrue($visibleToMayra->contains($mayra->id));
        $this->assertFalse(
            $visibleToMayra->contains($superAdmin->id),
            'Mayra NO debe ver al super_admin (es su creador, no su descendiente).'
        );
    }

    public function test_force_deletes_obsolete_users_keeping_super_admin_and_team(): void
    {
        $this->seedDependencies();

        // Usuario viejo de prueba (con rol + bodega) que debe desaparecer.
        $obsolete = User::factory()->create(['email' => 'operador.copan@gmail.com']);
        $obsolete->assignRole('operador');
        $obsolete->warehouses()->sync([Warehouse::query()->where('code', 'OAC')->first()->id]);

        $this->seed(ProductionUsersSeeder::class);

        $this->assertNull(
            User::withTrashed()->where('email', 'operador.copan@gmail.com')->first(),
            'El usuario viejo debe quedar eliminado definitivamente.'
        );

        // Quedan exactamente el super_admin + los 6 del equipo.
        $this->assertSame(7, User::withTrashed()->count());
        $this->assertNotNull(User::query()->where('email', 'admin@gmail.com')->first());
    }

    public function test_password_is_12345678(): void
    {
        $this->seedDependencies();
        $this->seed(ProductionUsersSeeder::class);

        $mayra = User::query()->where('email', 'mayra@gmail.com')->first();

        $this->assertNotSame('12345678', $mayra->getAttributes()['password']);
        $this->assertTrue(Hash::check('12345678', $mayra->password));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedDependencies();

        $this->seed(ProductionUsersSeeder::class);
        $firstCount = User::withTrashed()->count();

        $this->seed(ProductionUsersSeeder::class);
        $secondCount = User::withTrashed()->count();

        $this->assertSame($firstCount, $secondCount, 'Re-ejecutar no debe duplicar usuarios.');
    }

    public function test_aborts_gracefully_if_super_admin_missing(): void
    {
        // Roles + bodegas pero SIN super_admin.
        $this->seed([RoleSeeder::class, WarehouseSeeder::class]);

        $this->seed(ProductionUsersSeeder::class);

        // Sin la raíz de la jerarquía no crea a nadie.
        $this->assertSame(0, User::query()->count());
    }
}
