<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste manual de centavos sobre un manifiesto.
 *
 * `amount` positivo = se da por recibido un faltante (difference era > 0).
 * `amount` negativo = se da por bueno un sobrante (difference era < 0).
 *
 * Nunca se edita ni se borra: si un ajuste estuvo mal, se registra otro
 * en sentido contrario. Un ajuste editable no sería auditable — es dinero
 * dado por bueno, tiene que quedar la cadena completa de quién hizo qué.
 */
class ManifestAdjustment extends Model
{
    use HasFactory;

    protected $fillable = [
        'manifest_id', 'amount', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
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
