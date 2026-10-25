<?php

namespace App\Models\Edt;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una preventa del EDT: un producto una sola vez (la escala
 * mayorista cuenta las cajas de ese código).
 *
 * Guarda copia del producto y de su precio del día (lista, costo, ISV,
 * unidades por caja). requested_* es lo que pidió el vendedor; boxes/units
 * lo que queda tras la revisión de la bodega. Los montos los pone
 * EdtOrderService.
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property string $product_code
 * @property string $description
 * @property int $units_per_box
 * @property string $isv_pct
 * @property string $list_price
 * @property string $box_cost
 * @property int $requested_boxes
 * @property int $requested_units
 * @property int $boxes
 * @property int $units
 * @property string|null $tier_name
 * @property string $box_price
 * @property string $unit_price
 * @property string $total
 */
class EdtOrderLine extends Model
{
    protected $table = 'edt_order_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'units_per_box' => 'integer',
            'isv_pct' => 'decimal:2',
            'list_price' => 'decimal:4',
            'box_cost' => 'decimal:4',
            'requested_boxes' => 'integer',
            'requested_units' => 'integer',
            'boxes' => 'integer',
            'units' => 'integer',
            'tier_discount_pct' => 'decimal:2',
            'box_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'isv' => 'decimal:2',
            'total' => 'decimal:2',
            'requested_total' => 'decimal:2',
            'cost_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(EdtOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(EdtProduct::class, 'product_id');
    }

    /** Unidades totales que quedan (cajas × unidades por caja + sueltas). */
    public function totalUnits(): int
    {
        return $this->boxes * $this->units_per_box + $this->units;
    }

    public function requestedUnits(): int
    {
        return $this->requested_boxes * $this->units_per_box + $this->requested_units;
    }

    /** ¿La bodega bajó la cantidad? */
    public function isReduced(): bool
    {
        return $this->totalUnits() < $this->requestedUnits();
    }
}
