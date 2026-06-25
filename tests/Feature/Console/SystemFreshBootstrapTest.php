<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Models\Warehouse;
use BezhanSalleh\FilamentShield\Support\Utils;
use Database\Seeders\RoleSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Tests del comando system:fresh-bootstrap.
 *
 * Cubrimos los tres guards, validación de credenciales del super_admin
 * y el flujo end-to-end:
 *  1. Guard de entorno: APP_ENV=production sin flag → aborta.
 *  2. Guard de confirmación: respuesta != BORRAR → aborta sin tocar BD.
 *  3. Validación de credenciales del super_admin (email/password) — flags.
 *  4. Ejecución completa: deja BD con 11 usuarios + 5 roles + permisos.
 *  5. super_admin queda con rol asignado y password hasheado.
 *
 * Los tests E2E (test_full_run_*) NO usan RefreshDatabase porque el
 * propio comando hace migrate:fresh — incompatible con las transacciones
 * que usa RefreshDatabase. Limpiamos en tearDown.
 *
 * Para no abrir prompts interactivos (que romperían en tests sin TTY)
 * usamos los flags --super-admin-email y --super-admin-password en
 * cada llamada al comando.
 */
class SystemFreshBootstrapTest extends TestCase
{
    /**
     * Credenciales de prueba para el super_admin que se crea en cada
     * test E2E. Se pasan vía --super-admin-email / --super-admin-password.
     */
    private const SA_EMAIL = 'super-admin-test@hozana.local';

    private const SA_PASSWORD = 'TestPassword123!';

    /**
     * tearDown: cada test deja la BD poblada (o parcial). Limpiamos
     * para que los siguientes tests del runner (que usan RefreshDatabase
     * + seeders) no encuentren super_admin role pre-existente.
     */
    protected function tearDown(): void
    {
        // Si APP_ENV quedó 'production' por algún test, lo restauramos.
        $this->app->detectEnvironment(fn () => 'testing');

        $this->artisan('migrate:fresh', ['--force' => true]);

        parent::tearDown();
    }

    public function test_aborts_in_production_without_force_flag(): void
    {
        $this->refreshDatabaseForGuardTest();

        // config(['app.env' => ...]) NO actualiza app()->environment()
        // cached. detectEnvironment() sí refresca el valor que el
        // comando lee via app()->environment().
        $this->app->detectEnvironment(fn () => 'production');
        $usersBefore = User::query()->count();

        $this->artisan('system:fresh-bootstrap')
            ->assertExitCode(1);

        $this->assertSame(
            $usersBefore,
            User::query()->count(),
            'El comando NO debe tocar BD si APP_ENV=production sin --force-production.'
        );
    }

    public function test_aborts_if_confirmation_is_not_exactly_borrar(): void
    {
        $this->refreshDatabaseForGuardTest();
        $this->seed([RoleSeeder::class, WarehouseSeeder::class]);

        $usersBefore = User::query()->count();

        // Cualquier respuesta distinta a "BORRAR" debe cancelar
        $this->artisan('system:fresh-bootstrap')
            ->expectsQuestion('Confirmación', 'borrar') // minúsculas
            ->assertExitCode(1);

        $this->assertSame(
            $usersBefore,
            User::query()->count(),
            'Una confirmación incorrecta NO debe tocar BD.'
        );
    }

    public function test_aborts_if_confirmation_is_yes(): void
    {
        $this->refreshDatabaseForGuardTest();
        $this->seed([RoleSeeder::class, WarehouseSeeder::class]);

        $this->artisan('system:fresh-bootstrap')
            ->expectsQuestion('Confirmación', 'yes')
            ->assertExitCode(1);

        // Roles deben seguir intactos
        $this->assertGreaterThan(0, Role::query()->count());
    }

    public function test_aborts_when_only_super_admin_email_is_provided(): void
    {
        $this->refreshDatabaseForGuardTest();

        // Pasar solo email sin password: error de uso, abortar.
        $this->artisan('system:fresh-bootstrap', [
            '--super-admin-email' => self::SA_EMAIL,
        ])
            ->expectsQuestion('Confirmación', 'BORRAR')
            ->assertExitCode(1);

        // No debió crear usuarios ni tocar BD significativamente.
        $this->assertSame(0, User::query()->count());
    }

    public function test_aborts_when_super_admin_email_is_invalid(): void
    {
        $this->refreshDatabaseForGuardTest();

        $this->artisan('system:fresh-bootstrap', [
            '--super-admin-email' => 'not-a-valid-email',
            '--super-admin-password' => self::SA_PASSWORD,
        ])
            ->expectsQuestion('Confirmación', 'BORRAR')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_aborts_when_super_admin_password_is_too_short(): void
    {
        $this->refreshDatabaseForGuardTest();

        $this->artisan('system:fresh-bootstrap', [
            '--super-admin-email' => self::SA_EMAIL,
            '--super-admin-password' => 'short', // 5 chars, mínimo es 8
        ])
            ->expectsQuestion('Confirmación', 'BORRAR')
            ->assertExitCode(1);

        $this->assertSame(0, User::query()->count());
    }

    public function test_full_run_leaves_db_with_expected_state(): void
    {
        $this->runBootstrap();

        // ── Roles ────────────────────────────────────────────────
        $this->assertSame(
            5,
            Role::query()->count(),
            'Deben existir los 5 roles: super_admin, admin, encargado, operador, finance.'
        );

        // ── Permisos Shield generados y asignados ────────────────
        $this->assertGreaterThan(0, Permission::query()->count());

        $admin = Role::query()->where('name', 'admin')->first();
        $this->assertGreaterThan(0, $admin->permissions()->count());
        $this->assertTrue($admin->hasPermissionTo('ViewAny:Manifest'));

        $encargado = Role::query()->where('name', 'encargado')->first();
        $this->assertTrue($encargado->hasPermissionTo('Update:Manifest'));
        $this->assertFalse($encargado->hasPermissionTo('Delete:Manifest'));

        // ── Permisos custom (CustomPermissionSeeder) generados y asignados ──
        $this->assertTrue(
            Permission::query()->where('name', 'Close:Manifest')->exists(),
            'CustomPermissionSeeder debe crear Close:Manifest.'
        );
        $this->assertTrue(
            $admin->hasPermissionTo('Reopen:Manifest'),
            'admin debe poder reabrir manifiestos.'
        );
        $this->assertTrue(
            $encargado->hasPermissionTo('Close:Manifest'),
            'encargado debe poder cerrar manifiestos de su bodega.'
        );
        $this->assertFalse(
            $encargado->hasPermissionTo('Reopen:Manifest'),
            'encargado NO debe poder reabrir (sensible, solo admin).'
        );

        // super_admin: Shield le asigna TODOS los permisos al promoverlo
        // (shield:super-admin syncPermissions internamente). Además queda
        // gateado por intercept_gate='before' como belt-and-suspenders.
        $superAdminRole = Role::query()->where('name', Utils::getSuperAdminName())->first();
        $this->assertGreaterThan(
            0,
            $superAdminRole->permissions()->count(),
            'Shield asigna todos los permisos al rol super_admin.'
        );

        // ── Bodegas ──────────────────────────────────────────────
        $this->assertSame(4, Warehouse::query()->count());
        foreach (['OAC', 'OAS', 'OAO', 'OAI'] as $code) {
            $this->assertNotNull(
                Warehouse::query()->where('code', $code)->first(),
                "Falta la bodega {$code}."
            );
        }

        // ── Usuarios ─────────────────────────────────────────────
        // 1 super_admin + 12 de bodega (3 roles × 4 bodegas) = 13
        $this->assertSame(13, User::query()->count());

        // El rol 'admin' existe pero NO se siembra usuario con él.
        $this->assertSame(0, User::query()->role('admin')->count());

        // Los 12 de bodega (email por slug de ciudad)
        $slugs = [
            'OAC' => 'copan',
            'OAS' => 'santabarbara',
            'OAO' => 'ocotepeque',
            'OAI' => 'intibuca',
        ];
        foreach ($slugs as $code => $slug) {
            foreach (['encargado', 'operador', 'finance'] as $role) {
                $email = "{$role}.{$slug}@gmail.com";
                $user = User::query()->where('email', $email)->first();
                $this->assertNotNull($user, "Falta {$email}");
                $this->assertTrue($user->hasRole($role));
                $this->assertNotEmpty($user->warehouseIds());
            }
        }
    }

    public function test_super_admin_is_created_with_provided_credentials_and_role(): void
    {
        $this->runBootstrap();

        $superAdmin = User::query()->where('email', self::SA_EMAIL)->first();

        $this->assertNotNull($superAdmin, 'El super_admin debe crearse con el email del flag.');
        $this->assertTrue(
            $superAdmin->hasRole(Utils::getSuperAdminName()),
            'shield:super-admin debe haber asignado el rol super_admin.'
        );
        $this->assertTrue(
            Hash::check(self::SA_PASSWORD, $superAdmin->password),
            'El password del super_admin debe ser hasheable contra el plaintext del flag.'
        );
        $this->assertTrue(
            $superAdmin->is_active,
            'El super_admin debe quedar activo (puede entrar al panel inmediatamente).'
        );
        $this->assertNotNull(
            $superAdmin->email_verified_at,
            'El super_admin debe quedar con email verificado para login sin fricción.'
        );
    }

    public function test_full_run_is_idempotent(): void
    {
        $this->runBootstrap();

        $usersFirst = User::query()->count();
        $rolesFirst = Role::query()->count();
        $adminPermsFirst = Role::query()->where('name', 'admin')->first()->permissions()->count();

        // Segunda corrida (debe dejar exactamente el mismo estado)
        $this->runBootstrap();

        $this->assertSame($usersFirst, User::query()->count());
        $this->assertSame($rolesFirst, Role::query()->count());
        $this->assertSame(
            $adminPermsFirst,
            Role::query()->where('name', 'admin')->first()->permissions()->count()
        );
    }

    public function test_runs_in_production_with_force_flag(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('system:fresh-bootstrap', [
            '--force-production' => true,
            '--super-admin-email' => self::SA_EMAIL,
            '--super-admin-password' => self::SA_PASSWORD,
        ])
            ->expectsQuestion('Confirmación', 'BORRAR')
            ->assertExitCode(0);

        // Aun en "producción" simulada, deja el sistema listo
        $this->assertSame(13, User::query()->count());
        $this->assertSame(5, Role::query()->count());
    }

    /**
     * Helper: corre el bootstrap completo con flags no-interactivos
     * para credenciales del super_admin. Único lugar donde se invoca
     * el comando para tests E2E (DRY).
     */
    private function runBootstrap(): void
    {
        $this->artisan('system:fresh-bootstrap', [
            '--super-admin-email' => self::SA_EMAIL,
            '--super-admin-password' => self::SA_PASSWORD,
        ])
            ->expectsQuestion('Confirmación', 'BORRAR')
            ->assertExitCode(0);
    }

    /**
     * Helper: simula un reset usando el flujo de migrate:fresh.
     * Usado por los tests de guard que NO ejecutan el bootstrap completo.
     */
    private function refreshDatabaseForGuardTest(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
    }
}
