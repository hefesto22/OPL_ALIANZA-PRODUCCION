<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 *
 * Default: factura importada, pagada contado, total aleatorio entre 100 y
 * 50,000 lempiras. Los campos fiscales de Honduras (importe_gravado_*, isv*)
 * se dejan en 0 — los tests que necesiten validar cálculo fiscal deben
 * pasarlos explícitamente vía state() en cada caso.
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $total = fake()->randomFloat(2, 100, 50000);

        return [
            'manifest_id' => Manifest::factory(),
            'warehouse_id' => Warehouse::factory(),
            'status' => 'imported',
            'jaremar_id' => fake()->numerify('########'),
            // Formato típico Jaremar: letra + 8 dígitos. Unique constraint.
            'invoice_number' => 'F'.fake()->unique()->numerify('########'),
            'lx_number' => fake()->numerify('LX######'),
            'order_number' => fake()->numerify('PED#####'),
            'invoice_date' => fake()->dateTimeBetween('-15 days', 'now'),
            'due_date' => null,
            'print_limit_date' => null,
            'seller_id' => fake()->numerify('VEN##'),
            'seller_name' => fake()->name(),
            'client_id' => fake()->numerify('CLI####'),
            'client_name' => fake()->company(),
            'client_rtn' => fake()->numerify('##############'),
            'deliver_to' => fake()->name(),
            'department' => fake()->state(),
            'municipality' => fake()->city(),
            'neighborhood' => fake()->streetName(),
            'address' => fake()->address(),
            'phone' => fake()->numerify('9###-####'),
            'longitude' => null,
            'latitude' => null,
            'route_number' => (string) fake()->numberBetween(1, 50),
            'cai' => null,
            'range_start' => null,
            'range_end' => null,
            'payment_type' => 'CONTADO',
            'credit_days' => 0,
            'invoice_type' => 'FAC',
            'invoice_status' => 1,
            'matriz_address' => null,
            'branch_address' => null,
            'importe_excento' => 0,
            'importe_exento_desc' => 0,
            'importe_exento_isv18' => 0,
            'importe_exento_isv15' => 0,
            'importe_exento_total' => 0,
            'importe_exonerado' => 0,
            'importe_exonerado_desc' => 0,
            'importe_exonerado_isv18' => 0,
            'importe_exonerado_isv15' => 0,
            'importe_exonerado_total' => 0,
            'importe_gravado' => 0,
            'importe_gravado_desc' => 0,
            'importe_gravado_isv18' => 0,
            'importe_gravado_isv15' => 0,
            'importe_gravado_total' => 0,
            'discounts' => 0,
            'isv18' => 0,
            'isv15' => 0,
            'total' => $total,
            'is_printed' => false,
            'printed_at' => null,
        ];
    }

    public function credit(int $days = 15): static
    {
        return $this->state(fn () => [
            'payment_type' => 'CREDITO',
            'credit_days' => $days,
        ]);
    }

    public function printed(): static
    {
        return $this->state(fn () => [
            'is_printed' => true,
            'printed_at' => now(),
        ]);
    }

    public function returned(): static
    {
        return $this->state(fn () => ['status' => 'returned']);
    }

    public function partialReturn(): static
    {
        return $this->state(fn () => ['status' => 'partial_return']);
    }

    public function pendingWarehouse(): static
    {
        return $this->state(fn () => [
            'status' => 'pending_warehouse',
            'warehouse_id' => null,
        ]);
    }
}
