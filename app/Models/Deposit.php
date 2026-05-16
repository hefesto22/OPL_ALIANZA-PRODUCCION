<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Deposit extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'manifest_id', 'amount', 'deposit_date',
        'bank', 'reference', 'observations',
        'receipt_image', 'receipt_image_uploaded_at',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'deposit_date' => 'date',
            'amount' => 'decimal:2',
            'receipt_image_uploaded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ─── Scopes ───────────────────────────────────────────────
    //
    // Un depósito cancelado existe en BD (para auditoría y trazabilidad)
    // pero no cuenta como dinero ingresado al manifiesto. Toda query que
    // sume montos para reportes financieros debe usar active() — el global
    // scope de SoftDeletes ya excluye los soft-deleted pero NO los cancelados.

    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at');
    }

    public function scopeCancelled($query)
    {
        return $query->whereNotNull('cancelled_at');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount', 'deposit_date', 'bank', 'reference'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Ruta firmada y temporal para ver el comprobante.
     *
     * TTL corto (30 minutos): la imagen es un comprobante bancario sensible.
     * Si el operador deja un modal abierto y vuelve después, el link expira
     * — fresh URL en el próximo page render. Es trade-off intencional UX/seguridad.
     *
     * Seguridad en capas: signed URL acota tiempo, middleware `auth` exige
     * sesión, y DepositPolicy::view (invocada en el controller) exige que
     * el usuario pertenezca a la bodega del manifest. Tres barreras
     * independientes — si una falla, las otras siguen protegiendo.
     */
    public function getReceiptUrlAttribute(): ?string
    {
        return $this->receipt_image
            ? URL::temporarySignedRoute('deposits.receipt', now()->addMinutes(30), ['deposit' => $this->id])
            : null;
    }

    /**
     * Elimina el archivo físico del comprobante del disco, si existe.
     * Llamar antes de borrar el modelo o al reemplazar la imagen.
     */
    public function deleteReceiptImage(): void
    {
        if (! $this->receipt_image) {
            return;
        }

        if (Storage::disk('local')->exists($this->receipt_image)) {
            Storage::disk('local')->delete($this->receipt_image);
        }

        // Limpiar el campo en BD para que no quede un apuntador roto.
        // saveQuietly() evita disparar eventos/observers innecesarios.
        $this->receipt_image = null;
        $this->receipt_image_uploaded_at = null;
        $this->saveQuietly();
    }

    // ─── Relaciones ───────────────────────────────────────────

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
