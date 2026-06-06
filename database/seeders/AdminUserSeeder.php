<?php

namespace Database\Seeders;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;

/**
 * @deprecated A partir de 2026-05-23, el super_admin se crea exclusivamente vía:
 *     - `php artisan system:fresh-bootstrap` (recomendado, integral)
 *     - `php artisan shield:super-admin` (manual, patrón nativo Shield)
 *
 *  Esta clase queda en el repo por compatibilidad (algún despliegue
 *  legacy podría seguir invocándola), pero NO está incluida en
 *  DatabaseSeeder ni se debe usar en código nuevo. Razones:
 *
 *  1. Usaba defaults inseguros (admin@gmail.com / password) si las
 *     vars ADMIN_EMAIL / ADMIN_PASSWORD no estaban en .env — fuga de
 *     credenciales esperando ocurrir.
 *  2. Hacía bcrypt() manual + cast 'hashed' del modelo = doble
 *     hashing (Hash::needsRehash lo salva, pero es confuso).
 *  3. No respetaba el patrón canónico shield:super-admin que asigna
 *     el rol con los permisos correctamente.
 *
 *  Para reactivar (solo si REALMENTE necesitas el flujo legacy):
 *   1. Define ADMIN_EMAIL y ADMIN_PASSWORD en .env (NO uses defaults).
 *   2. `php artisan db:seed --class=AdminUserSeeder`
 *   3. Luego asegúrate de correr `shield:super-admin --user=<id>`
 *      para que el rol tenga los permisos correctos.
 *
 *  Las credenciales se leen desde el .env:
 *    ADMIN_NAME=...
 *    ADMIN_EMAIL=...
 *    ADMIN_PASSWORD=...
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@gmail.com')],
            [
                'name' => env('ADMIN_NAME', 'admin'),
                'password' => bcrypt(env('ADMIN_PASSWORD', 'password')),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->assignRole(Utils::getSuperAdminName());
    }
}
