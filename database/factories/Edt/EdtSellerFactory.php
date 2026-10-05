<?php

namespace Database\Factories\Edt;

use App\Models\Edt\EdtSeller;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Edt\EdtModule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

/**
 * Vendedores del EDT para tests: crea también su usuario, amarrado a la
 * misma bodega, y le da el rol vendedor_edt si ese rol existe (lo crea
 * EdtPermissionSeeder). En la app real el alta pasa por EdtSellerService.
 *
 * @extends Factory<EdtSeller>
 */
class EdtSellerFactory extends Factory
{
    protected $model = EdtSeller::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('V-####'),
            'name' => fake()->name(),
            'phone' => fake()->numerify('9###-####'),
            'warehouse_id' => Warehouse::factory(),
            'user_id' => fn (array $attributes): int => User::factory()
                ->forWarehouse((int) $attributes['warehouse_id'])
                ->create(['is_active' => true])
                ->id,
            'is_active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (EdtSeller $seller): void {
            if (Role::query()->where('name', EdtModule::SELLER_ROLE)->exists()) {
                $seller->user->assignRole(EdtModule::SELLER_ROLE);
            }
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
