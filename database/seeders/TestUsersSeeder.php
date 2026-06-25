<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Warehouse;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Crea los usuarios de operación inicial del sistema (uno por rol y bodega).
 *
 * Estructura jerárquica:
 *
 *     super_admin (creado por system:fresh-bootstrap — admin@gmail.com)
 *         ├── encargado.copan / operador.copan / finance.copan          ← OAC (Copán)
 *         ├── encargado.santabarbara / operador… / finance…             ← OAS (Santa Bárbara)
 *         ├── encargado.ocotepeque / operador… / finance…               ← OAO (Ocotepeque)
 *         └── encargado.intibuca / operador… / finance…                 ← OAI (Intibucá)
 *
 * Total: 12 usuarios (3 roles × 4 bodegas). El super_admin lo crea el
 * comando de bootstrap y es la raíz de la jerarquía (created_by).
 *
 * NO se crea un usuario con rol 'admin' (gestor global). El rol existe en
 * el sistema con su matriz de permisos, pero se asigna desde el panel si
 * el negocio lo necesita. Si quieres volver a sembrar un admin global,
 * agrégalo aquí con warehouse_id = null.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  EMAIL — "que diga de dónde es"
 * ──────────────────────────────────────────────────────────────────────
 *  Patrón: '{rol}.{ciudad}@gmail.com' → operador.ocotepeque@gmail.com.
 *  El slug de ciudad hace el correo legible para un humano (a diferencia
 *  del código OAO). Ver self::WAREHOUSE_SLUG.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  PASSWORD
 * ──────────────────────────────────────────────────────────────────────
 *  Se lee de TEST_USER_PASSWORD en .env, con fallback a 'Hozana@2026'.
 *  Password compartido para los 12 usuarios — apropiado para
 *  QA/bootstrap inicial donde cada quien cambia el suyo al primer login.
 *  Para usar '12345678' en local: define TEST_USER_PASSWORD=12345678 en .env.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  IDEMPOTENCIA
 * ──────────────────────────────────────────────────────────────────────
 *  firstOrCreate por email. Re-ejecutar NO duplica ni cambia passwords
 *  existentes. Para resetear, usa system:fresh-bootstrap.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  NO se incluye en DatabaseSeeder por seguridad
 * ──────────────────────────────────────────────────────────────────────
 *  Crea usuarios con password conocido. Se invoca explícitamente desde
 *  system:fresh-bootstrap o `db:seed --class=TestUsersSeeder`.
 */
class TestUsersSeeder extends Seeder
{
    /**
     * Bodegas activas y su slug de ciudad para el email.
     *
     * @var array<string, string> código => slug-ciudad
     */
    private const WAREHOUSE_SLUG = [
        'OAC' => 'copan',
        'OAS' => 'santabarbara',
        'OAO' => 'ocotepeque',
        'OAI' => 'intibuca',
    ];

    /**
     * Roles de bodega que se crean por cada bodega.
     *
     * @var array<int, string>
     */
    private const WAREHOUSE_ROLES = ['encargado', 'operador', 'finance'];

    public function run(): void
    {
        $password = env('TEST_USER_PASSWORD', 'Hozana@2026');

        // ── Pre-flight: requiere rol super_admin + usuario con ese rol + bodegas ──
        $superAdminRoleName = Utils::getSuperAdminName();

        if (! Role::query()->where('name', $superAdminRoleName)->exists()) {
            $this->command?->error(
                "[TestUsersSeeder] No existe el rol '{$superAdminRoleName}'. ".
                'Corre primero: php artisan db:seed (incluye RoleSeeder)'
            );

            return;
        }

        $superAdmin = User::query()
            ->role($superAdminRoleName)
            ->first();

        if (! $superAdmin) {
            $this->command?->error(
                '[TestUsersSeeder] No existe ningún usuario con rol super_admin. '.
                'Corre primero el bootstrap (system:fresh-bootstrap).'
            );

            return;
        }

        $codes = array_keys(self::WAREHOUSE_SLUG);

        $warehouses = Warehouse::query()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        if ($warehouses->count() !== count($codes)) {
            $missing = collect($codes)
                ->diff($warehouses->pluck('code'))
                ->implode(', ');

            $this->command?->error(
                "[TestUsersSeeder] Faltan bodegas: {$missing}. ".
                'Corre primero: php artisan db:seed (incluye WarehouseSeeder)'
            );

            return;
        }

        // Una sola transacción: si algo falla, no quedan usuarios huérfanos.
        DB::transaction(function () use ($superAdmin, $warehouses, $password) {
            foreach (self::WAREHOUSE_SLUG as $code => $slug) {
                $warehouse = $warehouses->get($code);

                foreach (self::WAREHOUSE_ROLES as $role) {
                    $this->createUserWithRole(
                        email: "{$role}.{$slug}@gmail.com",
                        name: ucfirst($role).' '.$code,
                        password: $password,
                        role: $role,
                        warehouseId: $warehouse->id,
                        createdBy: $superAdmin->id,
                    );
                }
            }
        });

        $total = count(self::WAREHOUSE_SLUG) * count(self::WAREHOUSE_ROLES);

        $this->command?->info(
            "[TestUsersSeeder] {$total} usuarios de bodega provistos ".
            '(3 roles × 4 bodegas).'
        );
    }

    /**
     * Crea (o recupera) un usuario y le asigna el rol indicado.
     *
     * El cast 'password' => 'hashed' del modelo User hashea — pasamos
     * plaintext. assignRole es idempotente (syncWithoutDetaching interno).
     */
    private function createUserWithRole(
        string $email,
        string $name,
        string $password,
        string $role,
        ?int $warehouseId,
        ?int $createdBy,
    ): User {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'is_active' => true,
                'email_verified_at' => now(),
                'warehouse_id' => $warehouseId,
                'created_by' => $createdBy,
            ]
        );

        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user;
    }
}
