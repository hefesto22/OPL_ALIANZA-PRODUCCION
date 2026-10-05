<?php

namespace Database\Factories\Edt;

use App\Models\Edt\EdtClient;
use App\Models\Geo\Municipality;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Clientes del EDT para tests.
 *
 * El código queda vacío a propósito: lo asigna EdtClientObserver (C-000001…),
 * igual que en un alta real sin código escrito. La zona sale del catálogo
 * oficial que crea la migración (hn_municipalities), así que siempre es un
 * municipio real de su departamento.
 *
 * @extends Factory<EdtClient>
 */
class EdtClientFactory extends Factory
{
    protected $model = EdtClient::class;

    public function definition(): array
    {
        return [
            'code' => null,
            'warehouse_id' => Warehouse::factory(),
            'name' => fake()->name(),
            'business_name' => 'PULPERÍA '.fake()->lastName(),
            'business_type' => 'PULPERÍA',
            'rtn' => null,
            'phone' => fake()->numerify('9###-####'),
            'municipality_id' => fn (): int => (int) Municipality::query()->inRandomOrder()->value('id'),
            'department_id' => fn (array $attributes): int => (int) Municipality::query()
                ->whereKey($attributes['municipality_id'])
                ->value('department_id'),
            'neighborhood' => 'BARRIO '.fake()->lastName(),
            'address' => fake()->streetAddress(),
            'credit_enabled' => false,
            'credit_limit' => null,
            'credit_days' => null,
            'is_active' => true,
        ];
    }

    /**
     * Ubica al cliente en un municipio por su código INE (ej. '0401' =
     * Santa Rosa de Copán), con su departamento.
     */
    public function inMunicipality(string $code): static
    {
        return $this->state(function () use ($code): array {
            $municipality = Municipality::query()->where('code', $code)->firstOrFail();

            return [
                'municipality_id' => $municipality->id,
                'department_id' => $municipality->department_id,
            ];
        });
    }

    public function withCredit(?string $limit = '5000.00', ?int $days = 30): static
    {
        return $this->state(fn (): array => [
            'credit_enabled' => true,
            'credit_limit' => $limit,
            'credit_days' => $days,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
