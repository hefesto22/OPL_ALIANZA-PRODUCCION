<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name'      => fake()->company(),
            'rtn'       => fake()->numerify('##############'),
            'email'     => fake()->unique()->safeEmail(),
            'phone'     => fake()->numerify('9###-####'),
            'address'   => fake()->streetAddress(),
            'api_url'   => 'https://api.example.test',
            // Importante: el modelo Supplier cifra api_key con el cast
            // 'encrypted', y el resultado cifrado es ~6× más largo que
            // el input (base64 de iv+value+mac+tag). La columna en BD es
            // VARCHAR(255), así que necesitamos un input chico para que
            // el resultado cifrado entre. Con 16 chars quedamos cómodos.
            // NOTA (bug latente de producción): si algún día llega un
            // api_key real de Jaremar de 40+ chars, la columna debería
            // migrarse a TEXT para evitar truncamiento en prod.
            'api_key'   => \Illuminate\Support\Str::random(16),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
