<?php

namespace Database\Factories;

use App\Models\Deposit;
use App\Models\DepositAllocation;
use App\Models\Manifest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DepositAllocation>
 *
 * Ojo al usarla en tests: crear allocations sueltas ROMPE la invariante
 * SUM(allocations) == deposits.amount. Es correcto para probar el modelo o
 * los cálculos de Manifest, pero para probar flujo real hay que ir por
 * DepositService::createDeposit(), que es quien mantiene la invariante.
 */
class DepositAllocationFactory extends Factory
{
    protected $model = DepositAllocation::class;

    public function definition(): array
    {
        return [
            'deposit_id' => Deposit::factory(),
            'manifest_id' => Manifest::factory(),
            'amount' => fake()->randomFloat(2, 10, 1000),
            'created_by' => null,
        ];
    }
}
