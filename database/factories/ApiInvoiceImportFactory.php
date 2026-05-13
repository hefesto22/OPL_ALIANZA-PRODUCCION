<?php

namespace Database\Factories;

use App\Models\ApiInvoiceImport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiInvoiceImport>
 *
 * Default: batch recién recibido (`status = received`), payload mínimo válido,
 * contadores en cero porque aún no se procesó. Los tests de idempotencia y
 * conflict-resolution dependen de esta factory para fixtures de imports
 * históricos.
 *
 * Importante: `batch_uuid` y `payload_hash` deben ser únicos por instancia.
 * El hash se calcula desde el raw_payload — si los tests sobrescriben el
 * payload, también deben recalcular el hash o el de-dup por payload_hash
 * (mecanismo de idempotencia) no se va a ejercitar correctamente.
 */
class ApiInvoiceImportFactory extends Factory
{
    protected $model = ApiInvoiceImport::class;

    public function definition(): array
    {
        $payload = [
            'batch' => [
                ['invoice_number' => fake()->unique()->numerify('F########')],
            ],
        ];

        return [
            'batch_uuid' => (string) Str::uuid(),
            'api_key_hint' => substr(hash('sha256', fake()->uuid()), 0, 8),
            'ip_address' => fake()->ipv4(),
            'total_received' => 1,
            'raw_payload' => $payload,
            'payload_hash' => hash('sha256', json_encode($payload)),
            'status' => 'received',
            'invoices_inserted' => 0,
            'invoices_updated' => 0,
            'invoices_unchanged' => 0,
            'invoices_pending_review' => 0,
            'invoices_rejected' => 0,
            'warnings' => null,
            'errors' => null,
            'failure_message' => null,
        ];
    }

    /**
     * Import procesado exitosamente: status processed, 1 factura insertada.
     */
    public function processed(): static
    {
        return $this->state(fn () => [
            'status' => 'processed',
            'invoices_inserted' => 1,
        ]);
    }

    /**
     * Import parcial: se procesó pero hay conflictos pendientes de revisión.
     * Es el escenario que dispara la cola de revisión humana.
     */
    public function partial(int $pendingReview = 1): static
    {
        return $this->state(fn () => [
            'status' => 'partial',
            'invoices_pending_review' => $pendingReview,
            'warnings' => [['note' => 'Diferencia detectada en total']],
        ]);
    }

    /**
     * Import fallido globalmente (excepción durante el procesamiento).
     */
    public function failed(string $message = 'Unexpected error during import'): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'failure_message' => $message,
        ]);
    }
}
