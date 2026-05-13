<?php

namespace Database\Factories;

use App\Models\Manifest;
use App\Models\ManifestWarehouseTotal;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManifestWarehouseTotal>
 *
 * Default: agregado en cero, igual que cuando se crea un nuevo manifiesto.
 * La tabla tiene unique(manifest_id, warehouse_id) — cuando la factory crea
 * sus propios manifest+warehouse vía relaciones, no hay colisión; cuando los
 * tests reutilizan instancias, deben asegurar la combinación única.
 *
 * Los totales se rellenan normalmente por ManifestService::recalculateTotals(),
 * no manualmente. Esta factory existe principalmente para tests que necesitan
 * fixtures de slices ya calculados (ej. reportes por bodega).
 */
class ManifestWarehouseTotalFactory extends Factory
{
    protected $model = ManifestWarehouseTotal::class;

    public function definition(): array
    {
        return [
            'manifest_id' => Manifest::factory(),
            'warehouse_id' => Warehouse::factory(),
            'total_invoices' => 0,
            'total_returns' => 0,
            'total_to_deposit' => 0,
            'total_deposited' => 0,
            'difference' => 0,
            'invoices_count' => 0,
            'returns_count' => 0,
            'clients_count' => 0,
        ];
    }

    /**
     * Slice con totales realistas: 10 facturas, 1 devolución parcial,
     * todavía sin depositar. Útil para tests de reportes y widgets.
     */
    public function withTotals(): static
    {
        return $this->state(fn () => [
            'total_invoices' => 25000.00,
            'total_returns' => 1500.00,
            'total_to_deposit' => 23500.00,
            'total_deposited' => 0.00,
            'difference' => 23500.00,
            'invoices_count' => 10,
            'returns_count' => 1,
            'clients_count' => 8,
        ]);
    }

    /**
     * Slice cuadrado: lo depositado iguala lo esperado, diferencia cero.
     * Estado terminal feliz del ciclo bodega → banco.
     */
    public function settled(): static
    {
        return $this->state(fn () => [
            'total_invoices' => 25000.00,
            'total_returns' => 1500.00,
            'total_to_deposit' => 23500.00,
            'total_deposited' => 23500.00,
            'difference' => 0.00,
            'invoices_count' => 10,
            'returns_count' => 1,
            'clients_count' => 8,
        ]);
    }
}
