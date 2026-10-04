<?php

namespace Database\Factories\Edt;

use App\Models\Edt\EdtPriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Ojo: la migración ya siembra MAYORISTA 1 (25 cajas) y MAYORISTA 2 (50).
 * min_boxes es único, así que esta factory usa valores altos por defecto.
 *
 * @extends Factory<EdtPriceTier>
 */
class EdtPriceTierFactory extends Factory
{
    protected $model = EdtPriceTier::class;

    public function definition(): array
    {
        return [
            'name' => 'ESCALA '.fake()->unique()->numberBetween(1000, 9999),
            'min_boxes' => fake()->unique()->numberBetween(100, 900),
            'discount_pct' => '8.00',
            'is_active' => true,
        ];
    }
}
