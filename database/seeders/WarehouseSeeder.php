<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/**
 * Crea las 4 bodegas operativas de Distribuidora Hozana.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  CÓDIGOS Y UBICACIÓN REAL (occidente de Honduras)
 * ──────────────────────────────────────────────────────────────────────
 *   OAC → Copán          (cabecera: Santa Rosa de Copán)
 *   OAS → Santa Bárbara  (cabecera: Santa Bárbara)
 *   OAO → Ocotepeque     (cabecera: Ocotepeque)
 *   OAI → Intibucá       (cabecera: La Esperanza)   ← bodega nueva
 *
 *  IDEMPOTENTE: firstOrCreate por `code`. Re-ejecutar NO duplica ni
 *  pisa nombres editados manualmente desde el panel (la clave es el
 *  código, no la fila completa).
 */
class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        $warehouses = [
            [
                'code' => 'OAC',
                'name' => 'Bodega Copán',
                'city' => 'Santa Rosa de Copán',
                'department' => 'Copán',
                'is_active' => true,
            ],
            [
                'code' => 'OAS',
                'name' => 'Bodega Santa Bárbara',
                'city' => 'Santa Bárbara',
                'department' => 'Santa Bárbara',
                'is_active' => true,
            ],
            [
                'code' => 'OAO',
                'name' => 'Bodega Ocotepeque',
                'city' => 'Ocotepeque',
                'department' => 'Ocotepeque',
                'is_active' => true,
            ],
            [
                'code' => 'OAI',
                'name' => 'Bodega Intibucá',
                'city' => 'La Esperanza',
                'department' => 'Intibucá',
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
