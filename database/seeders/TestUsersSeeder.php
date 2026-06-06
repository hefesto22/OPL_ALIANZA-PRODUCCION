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
 * Crea los usuarios de operación inicial del sistema.
 *
 * Estructura jerárquica (refleja la realidad operativa OPL Alianza):
 *
 *     super_admin (creado por AdminUserSeeder)
 *         └── admin OPL Alianza (oplalianza@gmail.com)
 *                   ├── encargadoOAC, operadorOAC, financeOAC   ← bodega Choloma
 *                   ├── encargadoOAS, operadorOAS, financeOAS   ← bodega Santa Rosa
 *                   └── encargadoOAO, operadorOAO, financeOAO   ← bodega Omoa
 *
 * Total: 10 usuarios creados aquí (1 admin + 9 de bodega).
 * El super_admin lo crea AdminUserSeeder y es la raíz de la jerarquía.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  PASSWORD
 * ──────────────────────────────────────────────────────────────────────
 *  Se lee de TEST_USER_PASSWORD en .env, con fallback a 'Hozana@2026'.
 *  Es un único password compartido para los 10 usuarios — esto es
 *  apropiado para QA/staging/bootstrap inicial donde el equipo cambiará
 *  su password individual al primer login.
 *
 *  Si necesitas password distinto por usuario en producción real, ese
 *  es trabajo del admin desde el panel — no del seeder.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  IDEMPOTENCIA
 * ──────────────────────────────────────────────────────────────────────
 *  Usa firstOrCreate por email. Re-ejecutar el seeder NO duplica
 *  usuarios ni cambia passwords existentes. Si necesitas resetear los
 *  passwords usa el comando system:fresh-bootstrap que reconstruye
 *  todo desde cero.
 *
 *  Re-asigna el rol siempre (assignRole es idempotente en Spatie:
 *  internamente usa syncWithoutDetaching).
 *
 * ──────────────────────────────────────────────────────────────────────
 *  NO se incluye en DatabaseSeeder por seguridad
 * ──────────────────────────────────────────────────────────────────────
 *  Este seeder NO está en DatabaseSeeder::$call para evitar que un
 *  db:seed accidental en producción cree usuarios con password
 *  conocido. Se invoca explícitamente desde:
 *    - system:fresh-bootstrap (recomendado)
 *    - php artisan db:seed --class=TestUsersSeeder (manual)
 */
class TestUsersSeeder extends Seeder
{
    /**
     * Definición de los usuarios de bodega.
     * Formato: [código_bodega, rol] — el email se deriva del patrón
     * '{rol}{código}@gmail.com' para mantener consistencia con la
     * convención actual del sistema productivo.
     */
    private const WAREHOUSE_USERS = [
        ['OAC', 'encargado'],
        ['OAC', 'operador'],
        ['OAC', 'finance'],
        ['OAS', 'encargado'],
        ['OAS', 'operador'],
        ['OAS', 'finance'],
        ['OAO', 'encargado'],
        ['OAO', 'operador'],
        ['OAO', 'finance'],
    ];

    private const ADMIN_EMAIL = 'oplalianza@gmail.com';

    private const ADMIN_NAME = 'AdminOPLAlianza';

    public function run(): void
    {
        $password = env('TEST_USER_PASSWORD', 'Hozana@2026');

        // ── Pre-flight: requiere rol super_admin + usuario con ese rol + 3 bodegas ──
        // Cheque del ROL primero: Spatie lanza RoleDoesNotExist al usar
        // ->role(X) si el rol no existe en la tabla roles. Hay que
        // chequear existencia ANTES de invocar el scope.
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
                'Corre primero: php artisan db:seed (incluye AdminUserSeeder)'
            );

            return;
        }

        $warehouses = Warehouse::query()
            ->whereIn('code', ['OAC', 'OAS', 'OAO'])
            ->get()
            ->keyBy('code');

        if ($warehouses->count() !== 3) {
            $missing = collect(['OAC', 'OAS', 'OAO'])
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
            // ── 1. Admin OPL Alianza (hijo del super_admin) ────────
            $admin = $this->createUserWithRole(
                email: self::ADMIN_EMAIL,
                name: self::ADMIN_NAME,
                password: $password,
                role: 'admin',
                warehouseId: null,
                createdBy: $superAdmin->id,
            );

            // ── 2. Usuarios de bodega (hijos del admin) ────────────
            foreach (self::WAREHOUSE_USERS as [$code, $role]) {
                $warehouse = $warehouses->get($code);

                $this->createUserWithRole(
                    email: strtolower($role).$code.'@gmail.com',
                    name: $role.$code,
                    password: $password,
                    role: $role,
                    warehouseId: $warehouse->id,
                    createdBy: $admin->id,
                );
            }
        });

        $this->command?->info(
            '[TestUsersSeeder] '.(count(self::WAREHOUSE_USERS) + 1).' usuarios provistos (1 admin + '.count(self::WAREHOUSE_USERS).' de bodega).'
        );
    }

    /**
     * Crea (o recupera) un usuario y le asigna el rol indicado.
     *
     * El cast 'password' => 'hashed' del modelo User se encarga del
     * hashing — pasamos plaintext. assignRole es idempotente.
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

        // Si el usuario ya existía pero le falta el rol (caso edge:
        // alguien lo creó manualmente sin rol), igual lo asigna.
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }

        return $user;
    }
}
