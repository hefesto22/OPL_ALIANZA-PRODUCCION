<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceReturn>
 *
 * Ojo: la tabla real se llama `returns` (ver $table en el modelo
 * InvoiceReturn). El modelo se llama "InvoiceReturn" porque `Return` es
 * palabra reservada en PHP y no se puede usar como nombre de clase.
 *
 * Default: devolución parcial pendiente de revisión. Los tests que quieran
 * otro escenario (approved, rejected, total) deben usar los helpers
 * ->approved(), ->rejected(), ->total().
 */
class InvoiceReturnFactory extends Factory
{
    protected $model = InvoiceReturn::class;

    public function definition(): array
    {
        return [
            'manifest_id'      => Manifest::factory(),
            'invoice_id'       => Invoice::factory(),
            'return_reason_id' => ReturnReason::factory(),
            'warehouse_id'     => Warehouse::factory(),
            'jaremar_return_id' => null,
            'type'             => 'partial',
            'status'           => 'pending',
            'manifest_number'  => null,
            'client_id'        => fake()->numerify('CLI####'),
            'client_name'      => fake()->company(),
            'return_date'      => fake()->dateTimeBetween('-10 days', 'now'),
            'processed_date'   => null,
            'processed_time'   => null,
            'total'            => fake()->randomFloat(2, 50, 5000),
            'rejection_reason' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status'      => 'approved',
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'No aplica'): static
    {
        return $this->state(fn () => [
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_at'      => now(),
        ]);
    }

    public function total(): static
    {
        return $this->state(fn () => ['type' => 'total']);
    }
}
