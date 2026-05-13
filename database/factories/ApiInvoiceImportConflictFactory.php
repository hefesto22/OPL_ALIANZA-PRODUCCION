<?php

namespace Database\Factories;

use App\Models\ApiInvoiceImport;
use App\Models\ApiInvoiceImportConflict;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiInvoiceImportConflict>
 *
 * Default: conflicto pendiente de revisión. Modela el caso más común:
 * Jaremar reenvía una factura ya importada con un total diferente, lo que
 * dispara la cola de revisión humana. Los snapshots `previous_values` /
 * `incoming_values` se guardan completos para que el revisor pueda comparar.
 *
 * Los states accepted() y rejected() requieren un User como resolver — la
 * factory genera uno via subfactory cuando no se provee, simulando un admin.
 */
class ApiInvoiceImportConflictFactory extends Factory
{
    protected $model = ApiInvoiceImportConflict::class;

    public function definition(): array
    {
        return [
            'api_invoice_import_id' => ApiInvoiceImport::factory(),
            'invoice_id' => Invoice::factory(),
            'invoice_number' => 'F'.fake()->unique()->numerify('########'),
            'manifest_number' => 'M'.fake()->numerify('######'),
            'previous_values' => ['total' => '1000.00'],
            'incoming_values' => ['total' => '1050.00'],
            'resolution' => 'pending',
            'resolved_by' => null,
            'resolved_at' => null,
            'resolution_notes' => null,
        ];
    }

    public function accepted(?string $notes = 'Cambio validado con Jaremar'): static
    {
        return $this->state(fn () => [
            'resolution' => 'accepted',
            'resolved_by' => User::factory(),
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    public function rejected(?string $notes = 'Datos previos correctos'): static
    {
        return $this->state(fn () => [
            'resolution' => 'rejected',
            'resolved_by' => User::factory(),
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }
}
