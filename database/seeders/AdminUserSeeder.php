<?php

namespace Database\Seeders;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;

/**
 * Crea el usuario administrador principal del sistema.
 *
 * Las credenciales se leen desde el .env:
 *   ADMIN_NAME=...
 *   ADMIN_EMAIL=...
 *   ADMIN_PASSWORD=...
 *
 * Si las variables no están definidas se usan los valores por defecto
 * (solo para entorno local/desarrollo).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@gmail.com')],
            [
                'name'              => env('ADMIN_NAME', 'admin'),
                'password'          => bcrypt(env('ADMIN_PASSWORD', 'password')),
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->assignRole(Utils::getSuperAdminName());
    }
}
