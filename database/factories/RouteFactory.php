<?php

namespace Database\Factories;

use App\Models\Route;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Route>
 *
 * Default: ruta activa con código numérico único (formato Jaremar tipo "230",
 * "231"). El campo `code` tiene unique constraint y `seller_id` / `seller_name`
 * son nullable porque no todas las rutas tienen vendedor asignado en Jaremar.
 *
 * El modelo Route usa LogsActivity (auditoría) y SoftDeletes, pero la factory
 * crea solo el registro base — los tests de auditoría deben disparar el cambio
 * que quieran loguear, no esperar que la factory lo haga.
 */
class RouteFactory extends Factory
{
    protected $model = Route::class;

    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'code' => fake()->unique()->numerify('###'),
            'name' => 'Ruta '.fake()->city(),
            'seller_id' => fake()->numerify('VEN##'),
            'seller_name' => fake()->name(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Ruta sin vendedor asignado (escenario válido en Jaremar para rutas
     * recién creadas o reasignadas).
     */
    public function withoutSeller(): static
    {
        return $this->state(fn () => [
            'seller_id' => null,
            'seller_name' => null,
        ]);
    }
}
