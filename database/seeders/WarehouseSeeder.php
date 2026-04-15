<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $warehouses = [
            [
                'code' => 'OAC',
                'name' => 'Bodega Choloma',
                'city' => 'Choloma',
                'department' => 'Cortés',
                'is_active' => true,
            ],
            [
                'code' => 'OAO',
                'name' => 'Bodega Omoa',
                'city' => 'Omoa',
                'department' => 'Cortés',
                'is_active' => true,
            ],
            [
                'code' => 'OAS',
                'name' => 'Bodega Santa Rosa',
                'city' => 'Santa Rosa de Copán',
                'department' => 'Copán',
                'is_active' => true,
            ],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::firstOrCreate(
                ['code' => $warehouse['code']],
                $warehouse
            );
        }
    }
}
