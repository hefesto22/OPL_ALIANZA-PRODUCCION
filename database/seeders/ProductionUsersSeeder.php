<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Models\Warehouse;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra el equipo REAL de operación de Distribuidora Hozana en producción
 * y elimina los usuarios de prueba (los 12 por ciudad del TestUsersSeeder).
 *
 * ──────────────────────────────────────────────────────────────────────
 *  JERARQUÍA (created_by → visibilidad en el panel de Usuarios)
 * ──────────────────────────────────────────────────────────────────────
 *  El panel aplica User::scopeVisibleTo(): el super_admin ve a todos; un
 *  usuario no-super ve solo a sí mismo + sus descendientes (CTE recursivo),
 *  NUNCA a su creador. Por eso el orden de creación define quién ve a quién:
 *
 *     super_admin (admin@gmail.com)        ← raíz, ya existe en prod
 *         └── Mayra Torres (admin, GLOBAL) ← la crea el super_admin
 *                 ├── Sophia      (finance)            OAC
 *                 ├── Arli Zavala (operador)           OAC
 *                 ├── Keyli       (operador + finance) OAO
 *                 ├── Ana Ivet    (operador + finance) OAS
 *                 └── Jovany      (operador + finance) OAI
 *
 *  Resultado: el super_admin ve a todos. Mayra ve solo a los 5 que ella
 *  creó (+ ella misma) y NO ve al super_admin. Los operadores no se ven
 *  entre sí.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  PASSWORD INICIAL
 * ──────────────────────────────────────────────────────────────────────
 *  '12345678' para todos — password de arranque, NO un secreto. Cada
 *  persona debe cambiarlo en su primer ingreso. El cast 'password' =>
 *  'hashed' del modelo User lo hashea automáticamente.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  BORRADO DE USUARIOS VIEJOS — force delete
 * ──────────────────────────────────────────────────────────────────────
 *  Se eliminan DEFINITIVAMENTE todos los usuarios que NO sean el super_admin
 *  ni uno de los 6 del equipo real. Antes de borrar se desvinculan roles y
 *  bodegas (pivotes). Si algún usuario viejo dejara datos con FK (depósitos,
 *  devoluciones, cierres de manifiesto), el force delete fallaría y TODA la
 *  transacción se revierte — apropiado para producción en estado baseline
 *  (sin datos operativos todavía).
 *
 * ──────────────────────────────────────────────────────────────────────
 *  NO está en DatabaseSeeder
 * ──────────────────────────────────────────────────────────────────────
 *  Es destructivo (borra usuarios). Se invoca SOLO de forma explícita:
 *      php artisan db:seed --class=ProductionUsersSeeder
 *  Idempotente: re-ejecutar no duplica ni cambia passwords existentes.
 */
class ProductionUsersSeeder extends Seeder
{
    /** Password de arranque para todo el equipo (se cambia al primer login). */
    private const PASSWORD = '12345678';

    /**
     * Equipo real, en orden de creación. created_by se resuelve en run():
     * Mayra la crea el super_admin; el resto los crea Mayra.
     *
     * Estructura: [email-localpart, nombre, [roles], [códigos de bodega]]
     * Bodegas vacías = usuario global (ve todas las bodegas).
     *
     * @var array<int, array{0:string,1:string,2:array<int,string>,3:array<int,string>}>
     */
    private const TEAM = [
        ['sophia', 'Sophia', ['finance'], ['OAC']],
        ['arli', 'Arli Zavala', ['operador'], ['OAC']],
        ['keyli', 'Keyli', ['operador', 'finance'], ['OAO']],
        ['ana', 'Ana Ivet', ['operador', 'finance'], ['OAS']],
        ['jovany', 'Jovany', ['operador', 'finance'], ['OAI']],
    ];

    public function run(): void
    {
        // ── Pre-flight: super_admin raíz de la jerarquía ───────────────
        $superAdmin = User::query()
            ->role(Utils::getSuperAdminName())
            ->first();

        if (! $superAdmin) {
            $this->command?->error(
                '[ProductionUsersSeeder] No existe ningún usuario super_admin. '.
                'Corre primero el bootstrap (system:fresh-bootstrap).'
            );

            return;
        }

        // ── Pre-flight: las 4 bodegas activas ──────────────────────────
        $codes = ['OAC', 'OAS', 'OAO', 'OAI'];
        $warehouses = Warehouse::query()
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        if ($warehouses->count() !== count($codes)) {
            $missing = collect($codes)->diff($warehouses->pluck('code'))->implode(', ');
            $this->command?->error(
                "[ProductionUsersSeeder] Faltan bodegas: {$missing}. ".
                'Corre primero: php artisan db:seed (incluye WarehouseSeeder)'
            );

            return;
        }

        DB::transaction(function () use ($superAdmin, $warehouses): void {
            // ── 1. Mayra (admin global), creada por el super_admin ─────
            $mayra = $this->upsertUser(
                email: 'mayra@gmail.com',
                name: 'Mayra Torres',
                roles: ['admin'],
                warehouseIds: [],          // global: sin bodega
                createdBy: $superAdmin->id,
            );

            // ── 2. El resto, creados por Mayra ─────────────────────────
            foreach (self::TEAM as [$local, $name, $roles, $whCodes]) {
                $warehouseIds = collect($whCodes)
                    ->map(fn (string $c) => $warehouses->get($c)->id)
                    ->all();

                $this->upsertUser(
                    email: "{$local}@gmail.com",
                    name: $name,
                    roles: $roles,
                    warehouseIds: $warehouseIds,
                    createdBy: $mayra->id,
                );
            }

            // ── 3. Borrar usuarios viejos (todos menos el equipo real) ─
            $keep = array_merge(
                [$superAdmin->email, 'mayra@gmail.com'],
                array_map(fn (array $u) => "{$u[0]}@gmail.com", self::TEAM),
            );

            $this->forceDeleteObsolete($keep);
        });

        $this->command?->info(
            '[ProductionUsersSeeder] Equipo real provisto (Mayra + 5 operativos) '.
            'y usuarios de prueba eliminados.'
        );
    }

    /**
     * Crea (o recupera) un usuario con sus roles y bodegas exactos.
     *
     * - firstOrCreate por email → idempotente, no pisa passwords existentes.
     * - syncRoles fija EXACTAMENTE los roles dados (quita los demás).
     * - warehouses()->sync fija EXACTAMENTE las bodegas (vacío = global).
     *
     * @param  array<int, string>  $roles
     * @param  array<int, int>  $warehouseIds
     */
    private function upsertUser(
        string $email,
        string $name,
        array $roles,
        array $warehouseIds,
        int $createdBy,
    ): User {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => self::PASSWORD,
                'is_active' => true,
                'email_verified_at' => now(),
                'created_by' => $createdBy,
            ],
        );

        // Idempotencia robusta: si el usuario YA existía (p.ej. creado a mano
        // antes), igual forzamos la jerarquía (created_by) y que esté activo,
        // para que el seeder sea la fuente de verdad. NO pisamos el password.
        if ($user->created_by !== $createdBy || ! $user->is_active) {
            $user->update([
                'created_by' => $createdBy,
                'is_active' => true,
            ]);
        }

        $user->syncRoles($roles);
        $user->warehouses()->sync($warehouseIds);

        return $user;
    }

    /**
     * Elimina DEFINITIVAMENTE los usuarios cuyo email no está en $keep.
     * Desvincula roles y bodegas antes de borrar para no dejar pivotes
     * huérfanos.
     *
     * @param  array<int, string>  $keep  Emails a conservar.
     */
    private function forceDeleteObsolete(array $keep): void
    {
        $obsolete = User::withTrashed()
            ->whereNotIn('email', $keep)
            ->get();

        foreach ($obsolete as $user) {
            $user->roles()->detach();
            $user->warehouses()->detach();
            $user->forceDelete();
        }
    }
}
