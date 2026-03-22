<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class InvoiceReturn extends Model
{
    use SoftDeletes, LogsActivity;

    protected $table = 'returns';

    protected $fillable = [
        'manifest_id', 'invoice_id', 'return_reason_id', 'warehouse_id',
        'jaremar_return_id', 'type', 'status', 'manifest_number',
        'client_id', 'client_name',
        'return_date', 'processed_date', 'processed_time',
        'total', 'rejection_reason',
        'created_by', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'return_date'    => 'date',
            'processed_date' => 'date',
            'reviewed_at'    => 'datetime',
            'total'          => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['type', 'status', 'total', 'return_reason_id', 'invoice_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ─── Scopes ───────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    // ─── Relaciones ───────────────────────────────────────────

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function returnReason(): BelongsTo
    {
        return $this->belongsTo(ReturnReason::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReturnLine::class, 'return_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ─── Helpers ──────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Una devolución solo puede editarse el mismo día calendario en que
     * fue creada. Después de medianoche queda bloqueada porque Jaremar
     * pudo haberla consumido vía API (GET /devoluciones).
     *
     * Se usa created_at (no return_date) porque return_date puede ser
     * retroactiva, pero la ventana de edición depende de cuándo fue
     * registrada en el sistema.
     */
    public function isEditableToday(): bool
    {
        return $this->created_at->isToday();
    }

    /**
     * Texto descriptivo del estado de edición — útil para tooltips en la UI.
     */
    public function getEditabilityLabel(): string
    {
        if ($this->isEditableToday()) {
            return 'Editable hasta las 23:59 de hoy';
        }

        return 'Bloqueada — registrada el ' . $this->created_at->format('d/m/Y');
    }
}