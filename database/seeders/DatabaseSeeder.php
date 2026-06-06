<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orquestador de seeders base — seguro para cualquier entorno.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  ORDEN DE EJECUCIÓN — IMPORTA
 * ──────────────────────────────────────────────────────────────────────
 *   1. RoleSeeder            → crea roles vacíos (sin permisos)
 *   2. SupplierSeeder        → crea proveedor Jaremar
 *   3. WarehouseSeeder       → crea las 3 bodegas (OAC, OAS, OAO)
 *   4. ReturnReasonSeeder    → crea 30 motivos de devolución
 *   5. RolePermissionSeeder  → asigna permisos a roles (graceful skip
 *                              si Shield aún no generó los permisos)
 *
 * ──────────────────────────────────────────────────────────────────────
 *  AdminUserSeeder fue RETIRADO de DatabaseSeeder
 * ──────────────────────────────────────────────────────────────────────
 *  El super_admin ya NO se crea desde aquí. Antes este seeder usaba
 *  defaults inseguros (admin@gmail.com / password) si no había vars de
 *  entorno. Ahora el super_admin se crea exclusivamente vía:
 *
 *    a) `php artisan system:fresh-bootstrap` (recomendado) — pregunta
 *       email + password en runtime con validación, usa el patrón
 *       canónico de shield:super-admin.
 *
 *    b) `php artisan shield:super-admin` (manual) — comando nativo
 *       de Filament Shield, interactivo, crea el user o promueve uno
 *       existente.
 *
 *  AdminUserSeeder queda como @deprecated en su archivo para compat.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  TestUsersSeeder NO SE INCLUYE — por seguridad
 * ──────────────────────────────────────────────────────────────────────
 *  TestUsersSeeder crea 10 usuarios con password conocido. Incluirlo
 *  aquí significaría que un db:seed accidental en producción crearía
 *  cuentas con credenciales débiles. Se invoca explícitamente desde
 *  system:fresh-bootstrap o `db:seed --class=TestUsersSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SupplierSeeder::class,
            WarehouseSeeder::class,
            ReturnReasonSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }
}
