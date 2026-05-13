<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Deposit extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'manifest_id', 'amount', 'deposit_date',
        'bank', 'reference', 'observations',
        'receipt_image', 'receipt_image_uploaded_at',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'deposit_date' => 'date',
            'amount' => 'decimal:2',
            'receipt_image_uploaded_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount', 'deposit_date', 'bank', 'reference'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Ruta interna autenticada para ver el comprobante.
     * Las imágenes se guardan en el disco 'local' (privado):
     *   storage/app/deposits/receipts/{uuid}.jpg
     * Solo accesibles a través de esta ruta con sesión activa.
     */
    public function getReceiptUrlAttribute(): ?string
    {
        return $this->receipt_image
            ? route('deposits.receipt', $this)
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
}
