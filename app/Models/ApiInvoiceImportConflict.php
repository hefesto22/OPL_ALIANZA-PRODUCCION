<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiInvoiceImportConflict extends Model
{
    use HasFactory;

    protected $fillable = [
        'api_invoice_import_id',
        'invoice_id',
        'invoice_number',
        'manifest_number',
        'previous_values',
        'incoming_values',
        'resolution',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'previous_values' => 'array',
            'incoming_values' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    // ─── Relaciones ───────────────────────────────────────────

    public function import(): BelongsTo
    {
        return $this->belongsTo(ApiInvoiceImport::class, 'api_invoice_import_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('resolution', 'pending');
    }

    // ─── Helpers ──────────────────────────────────────────────

    public function accept(int $userId, ?string $notes = null): void
    {
        // Aplicar los valores entrantes a la factura real
        $this->invoice->update($this->incoming_values);

        $this->update([
            'resolution' => 'accepted',
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);

        // Recalcular totales del manifiesto afectado
        $this->invoice->manifest->recalculateTotals();
    }

    public function reject(int $userId, ?string $notes = null): void
    {
        // No se toca la factura — se mantienen los valores anteriores
        $this->update([
            'resolution' => 'rejected',
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    public function getChangedFieldsCount(): int
    {
        return count($this->previous_values ?? []);
    }
}
