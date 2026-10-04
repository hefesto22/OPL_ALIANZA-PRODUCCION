<?php

namespace App\Models\Edt;

use App\Enums\Edt\PriceChangeReason;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Una fila del historial de precios de un producto del EDT.
 *
 * Solo se agregan filas: editar o borrar lanza excepción. Si un precio se
 * capturó mal, se corrige con un cambio nuevo (motivo CORRECCIÓN) y quedan
 * visibles el error y la corrección. Lo escribe EdtPriceHistoryService.
 *
 * @property int $id
 * @property int $product_id
 * @property PriceChangeReason $reason
 * @property string|null $note
 * @property string $list_price
 * @property int $units_per_box
 * @property string $isv_pct
 * @property string $operation_discount_pct
 * @property string $unit_cost
 * @property string $retail_unit_price
 * @property string $retail_box_price
 * @property list<array{tier_id: int|null, name: string, min_boxes: int, discount_pct: string, box_price: string, margin_pct?: string}> $wholesale_prices
 */
class EdtProductPriceHistory extends Model
{
    protected $table = 'edt_product_price_history';

    protected $fillable = [
        'product_id',
        'reason',
        'note',
        'list_price',
        'units_per_box',
        'isv_pct',
        'operation_discount_pct',
        'unit_cost',
        'retail_unit_price',
        'retail_box_price',
        'wholesale_prices',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reason' => PriceChangeReason::class,
            'list_price' => 'decimal:4',
            'units_per_box' => 'integer',
            'isv_pct' => 'decimal:2',
            'operation_discount_pct' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'retail_unit_price' => 'decimal:2',
            'retail_box_price' => 'decimal:2',
            'wholesale_prices' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('El historial de precios del EDT no se edita: se agrega un cambio nuevo.');
        });

        static::deleting(function (): never {
            throw new LogicException('El historial de precios del EDT no se borra.');
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EdtProduct::class, 'product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
