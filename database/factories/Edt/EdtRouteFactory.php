<?php

namespace Database\Factories\Edt;

use App\Models\Edt\EdtRoute;
use App\Models\Geo\Municipality;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rutas del EDT para tests. Sin municipios ni días por defecto; usar
 * covering() y visitingOn().
 *
 * @extends Factory<EdtRoute>
 */
class EdtRouteFactory extends Factory
{
    protected $model = EdtRoute::class;

    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'name' => 'RUTA '.fake()->unique()->bothify('??-###'),
            'visit_days' => [],
            'is_active' => true,
        ];
    }

    /**
     * Municipios de la ruta, por código INE (ej. '0401' = Santa Rosa de Copán).
     *
     * @param  list<string>  $municipalityCodes
     */
    public function covering(array $municipalityCodes): static
    {
        return $this->afterCreating(function (EdtRoute $route) use ($municipalityCodes): void {
            $route->municipalities()->attach(
                Municipality::query()->whereIn('code', $municipalityCodes)->pluck('id')
            );
        });
    }

    /**
     * Días de visita ISO (1 = lunes … 7 = domingo).
     *
     * @param  list<int>  $days
     */
    public function visitingOn(array $days): static
    {
        return $this->state(fn (): array => ['visit_days' => $days]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
