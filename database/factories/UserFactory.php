<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Vincula el usuario a una o varias bodegas (pivote user_warehouse).
     *
     * Reemplaza al antiguo `->create(['warehouse_id' => $id])`, que ya no
     * existe: la bodega es muchos-a-muchos. Acepta modelos o IDs.
     *
     * Ejemplos:
     *   User::factory()->forWarehouse($oac)->create();          // 1 bodega
     *   User::factory()->forWarehouse([$oac, $oas])->create();  // 2 bodegas
     */
    public function forWarehouse(\App\Models\Warehouse|int|array $warehouses): static
    {
        $ids = collect(is_array($warehouses) ? $warehouses : [$warehouses])
            ->map(fn ($w) => $w instanceof \App\Models\Warehouse ? $w->id : (int) $w)
            ->all();

        return $this->afterCreating(function (\App\Models\User $user) use ($ids): void {
            $user->warehouses()->syncWithoutDetaching($ids);
        });
    }
}
