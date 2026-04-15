<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 *
 * Hozana maneja 3 bodegas reales: OAC (Copán), OAS (Santa Bárbara) y OAO
 * (Ocotepeque). La factory ofrece un helper por cada una para que los tests
 * puedan generar datos con los códigos canónicos en vez de inventar strings.
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        // Default genérico: código aleatorio de 3 letras. Los tests que
        // necesiten una bodega concreta deben llamar a ->oac(), ->oas() o
        // ->oao() para evitar colisiones con el unique constraint del code.
        return [
            'code' => strtoupper(fake()->unique()->bothify('???')),
            'name' => 'Bodega '.fake()->city(),
            'city' => fake()->city(),
            'department' => fake()->state(),
            'address' => fake()->address(),
            'phone' => fake()->numerify('2###-####'),
            'is_active' => true,
        ];
    }

    public function oac(): static
    {
        return $this->state(fn () => [
            'code' => 'OAC',
            'name' => 'Oficina Administrativa Copán',
            'city' => 'Santa Rosa de Copán',
            'department' => 'Copán',
        ]);
    }

    public function oas(): static
    {
        return $this->state(fn () => [
            'code' => 'OAS',
            'name' => 'Oficina Administrativa Santa Bárbara',
            'city' => 'Santa Bárbara',
            'department' => 'Santa Bárbara',
        ]);
    }

    public function oao(): static
    {
        return $this->state(fn () => [
            'code' => 'OAO',
            'name' => 'Oficina Administrativa Ocotepeque',
            'city' => 'Ocotepeque',
            'department' => 'Ocotepeque',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
