<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        Supplier::firstOrCreate(
            ['name' => 'Grupo Jaremar de Honduras S.A. de C.V.'],
            [
                'rtn'       => '08019017952895',
                'email'     => 'finanzas@jaremar.com',
                'phone'     => '2238-2484',
                'address'   => 'BO. LA GUADALUPE CL. LAS ACACIAS, APTO. 13, ED. ITALIA M.D.C. F.M. HONDURAS',
                'is_active' => true,
            ]
        );
    }
}