<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del reparto de un depósito bancario: "de la boleta D,
 * HNL X van al manifiesto M".
 *
 * Es un registro de detalle puro — no se edita ni se cancela por sí solo.
 * Su ciclo de vida lo maneja íntegramente DepositService dentro de la
 * transacción del depósito padre. Por eso NO usa SoftDeletes ni LogsActivity:
 * la auditoría del movimiento vive en el depósito (canal `finance`), donde
 * el log registra el reparto completo como una sola operación de negocio.
 */
class DepositAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'deposit_id', 'manifest_id', 'amount', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Solo las aplicaciones que representan dinero vigente.
     *
     * Un depósito cancelado o borrado conserva sus allocations en BD (para
     * poder restaurarlo sin backfill), pero su dinero NO cuenta. Todo cálculo
     * financiero debe pasar por este scope — es el equivalente de
     * Deposit::scopeActive() en el lado del reparto.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereHas('deposit', fn (Builder $q) => $q->whereNull('cancelled_at'));
    }

    /**
     * Suma del dinero vigente aplicado a un manifiesto.
     *
     * Se escribe como join explícito en vez de whereHas: es la query que
     * corre en CADA recálculo de totales (importación, devolución, depósito).
     * El join usa el index (manifest_id) + PK de deposits; whereHas armaría
     * un subquery correlacionado innecesario.
     */
    public static function totalForManifest(int $manifestId): float
    {
        return (float) static::query()
            ->where('deposit_allocations.manifest_id', $manifestId)
            ->join('deposits', 'deposits.id', '=', 'deposit_allocations.deposit_id')
            ->whereNull('deposits.cancelled_at')
            ->whereNull('deposits.deleted_at')
            ->sum('deposit_allocations.amount');
    }

    public function deposit(): BelongsTo
    {
        return $this->belongsTo(Deposit::class);
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(Manifest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
