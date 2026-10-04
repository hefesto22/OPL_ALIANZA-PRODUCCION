<?php

namespace Database\Factories\Edt;

use App\Models\Edt\EdtSupplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EdtSupplier>
 */
class EdtSupplierFactory extends Factory
{
    protected $model = EdtSupplier::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('PRV-####'),
            'name' => fake()->company(),
            'rtn' => fake()->numerify('##############'),
            'contact_name' => fake()->name(),
            'phone' => fake()->numerify('9###-####'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->streetAddress(),
            // 15% es el "Descuento Operación" que usa hoy el Excel del cliente.
            'operation_discount_pct' => '15.00',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
