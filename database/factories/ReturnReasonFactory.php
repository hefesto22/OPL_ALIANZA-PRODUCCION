<?php

namespace Database\Factories;

use App\Models\ReturnReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReturnReason>
 *
 * Las categorías reales son BE (Bodega/Entrega), PNC (Producto No Conforme)
 * y GEN (General). La factory default crea una razón BE-01 válida.
 */
class ReturnReasonFactory extends Factory
{
    protected $model = ReturnReason::class;

    public function definition(): array
    {
        return [
            'jaremar_id' => fake()->numerify('####'),
            'code' => 'BE-'.fake()->unique()->numerify('##'),
            'category' => 'BE',
            'description' => fake()->sentence(4),
            'is_active' => true,
        ];
    }

    public function pnc(): static
    {
        return $this->state(fn () => [
            'code' => 'PNC-'.fake()->numerify('##'),
            'category' => 'PNC',
        ]);
    }

    public function general(): static
    {
        return $this->state(fn () => [
            'code' => 'GEN-'.fake()->numerify('##'),
            'category' => 'GEN',
        ]);
    }
}
