<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiInvoiceImport extends Model
{
    protected $fillable = [
        'batch_uuid',
        'api_key_hint',
        'ip_address',
        'total_received',
        'raw_payload',
        'payload_hash',
        'status',
        'invoices_inserted',
        'invoices_updated',
        'invoices_unchanged',
        'invoices_pending_review',
        'invoices_rejected',
        'warnings',
        'errors',
        'failure_message',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'warnings' => 'array',
            'errors' => 'array',
        ];
    }

    // ─── Relaciones ───────────────────────────────────────────

    public function conflicts(): HasMany
    {
        return $this->hasMany(ApiInvoiceImportConflict::class);
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopeWithPendingConflicts($query)
    {
        return $query->where('status', 'partial')
            ->whereHas('conflicts', fn ($q) => $q->where('resolution', 'pending'));
    }

    // ─── Helpers ──────────────────────────────────────────────

    public function markAsProcessed(array $summary): void
    {
        $hasPending = ($summary['invoices_pending_review'] ?? 0) > 0;

        $this->update([
            'status' => $hasPending ? 'partial' : 'processed',
            'invoices_inserted' => $summary['invoices_inserted'] ?? 0,
            'invoices_updated' => $summary['invoices_updated'] ?? 0,
            'invoices_unchanged' => $summary['invoices_unchanged'] ?? 0,
            'invoices_pending_review' => $summary['invoices_pending_review'] ?? 0,
            'invoices_rejected' => $summary['invoices_rejected'] ?? 0,
            'warnings' => $summary['warnings'] ?? null,
            'errors' => $summary['errors'] ?? null,
        ]);
    }

    public function markAsFailed(string $message): void
    {
        $this->update([
            'status' => 'failed',
            'failure_message' => $message,
        ]);
    }
}
