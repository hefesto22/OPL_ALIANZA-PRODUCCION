<?php

namespace Database\Factories;

use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Manifest>
 *
 * Nota sobre estados:
 * - pending    → recién creado, sin facturas importadas todavía
 * - processing → el job ProcessManifestImport está corriendo
 * - imported   → las facturas ya fueron procesadas (estado normal de trabajo)
 * - closed     → diferencia cuadrada y cerrado por un usuario
 *
 * Los totales se dejan en 0 a propósito: cualquier test que quiera trabajar
 * con totales reales debe llamar a $manifest->recalculateTotals() después
 * de crear las facturas/devoluciones/depósitos hijas, que es justo lo que
 * hace la aplicación real.
 */
class ManifestFactory extends Factory
{
    protected $model = Manifest::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'warehouse_id' => Warehouse::factory(),
            // Formato típico de Jaremar: MAN + 6 dígitos. Unique para no
            // chocar con el constraint de la columna `number`.
            'number' => 'MAN'.fake()->unique()->numerify('######'),
            'date' => fake()->dateTimeBetween('-30 days', 'now'),
            'status' => 'imported',
            'total_invoices' => 0,
            'total_returns' => 0,
            'total_to_deposit' => 0,
            'total_deposited' => 0,
            'difference' => 0,
            'invoices_count' => 0,
            'returns_count' => 0,
            'raw_json' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['status' => 'pending']);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => 'processing']);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }
}
