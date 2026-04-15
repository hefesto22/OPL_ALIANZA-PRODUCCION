<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            SupplierSeeder::class,
            WarehouseSeeder::class,
            ReturnReasonSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
