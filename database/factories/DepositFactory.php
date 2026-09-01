<?php

namespace Database\Factories;

use App\Models\Deposit;
use App\Models\Manifest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deposit>
 *
 * Default: depósito chico (HNL 100–1,000), banco BAC, fecha hoy, sin imagen
 * de comprobante. Los tests que necesiten un monto específico, banco
 * distinto, o un comprobante asociado, deben pasar el override en make().
 *
 * Nota: desde 2026-08-27 Deposit SÍ tiene `warehouse_id` propio — antes la
 * pertenencia se derivaba de manifest.warehouse_id, que en un manifiesto
 * multi-bodega no significa nada. El default lo deja en NULL a propósito
 * (espeja los depósitos históricos y los que el backfill no pudo atribuir);
 * los tests que necesiten un depósito imputado a una bodega usan
 * ->forWarehouse($bodega).
 */
class DepositFactory extends Factory
{
    protected $model = Deposit::class;

    public function definition(): array
    {
        return [
            'manifest_id' => Manifest::factory(),
            'amount' => fake()->randomFloat(2, 100, 1000),
            'deposit_date' => now()->toDateString(),
            'bank' => fake()->randomElement(['BAC', 'FICOHSA', 'ATLANTIDA', 'BANPAIS']),
            'reference' => 'REF-'.fake()->unique()->numerify('######'),
            'observations' => null,
            'receipt_image' => null,
            'receipt_image_uploaded_at' => null,
        ];
    }

    /**
     * Depósito imputado a una bodega concreta.
     */
    public function forWarehouse(\App\Models\Warehouse|int $warehouse): static
    {
        return $this->state(fn () => [
            'warehouse_id' => $warehouse instanceof \App\Models\Warehouse ? $warehouse->id : $warehouse,
        ]);
    }

    public function withReceipt(): static
    {
        return $this->state(fn () => [
            'receipt_image' => 'deposits/receipts/'.fake()->uuid().'.jpg',
            'receipt_image_uploaded_at' => now(),
        ]);
    }
}
