<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use BezhanSalleh\FilamentShield\Support\Utils;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Administrador',
                'password' => bcrypt('12345678'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );

        $admin->assignRole(Utils::getSuperAdminName());
    }
}