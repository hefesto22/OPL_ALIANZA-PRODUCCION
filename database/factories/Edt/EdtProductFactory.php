<?php

namespace Database\Factories\Edt;

use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtSupplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EdtProduct>
 */
class EdtProductFactory extends Factory
{
    protected $model = EdtProduct::class;

    public function definition(): array
    {
        return [
            'supplier_id' => EdtSupplier::factory(),
            'code' => fake()->unique()->numerify('27######'),
            'description' => fake()->words(3, true).' '.fake()->numberBetween(100, 900).'G',
            'category' => 'ALIMENTOS',
            'family' => 'CEREALES',
            'presentation' => 'GRAN DIA',
            'units_per_box' => 24,
            'isv_pct' => '15.00',
            'list_price' => '25.3600',
            'is_active' => true,
        ];
    }

    /** Exento de ISV (como las cervezas y bebidas del Excel del cliente). */
    public function exempt(): static
    {
        return $this->state(fn () => ['isv_pct' => '0.00']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
