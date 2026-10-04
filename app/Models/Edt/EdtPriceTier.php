<?php

namespace App\Models\Edt;

use App\Casts\Uppercase;
use App\Models\Edt\Concerns\SavesAtomically;
use App\Observers\Edt\EdtPriceTierObserver;
use App\Support\Edt\EdtModule;
use App\Traits\HasAuditFields;
use Database\Factories\Edt\EdtPriceTierFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Escala mayorista del EDT: desde `min_boxes` cajas DEL MISMO CÓDIGO aplica
 * `discount_pct` sobre el precio de lista (ver EdtPricingService).
 *
 * Cambiar, activar, desactivar o borrar una escala cambia los precios de
 * todos los productos: EdtPriceTierObserver escribe una fila de historial por
 * producto, dentro de la misma transacción (SavesAtomically).
 *
 * Ojo: saveQuietly(), updateQuietly() y los UPDATE masivos por query no
 * disparan el observer y NO dejan historial. No usarlos con este modelo.
 *
 * @property int $id
 * @property string $name
 * @property int $min_boxes
 * @property string $discount_pct
 * @property bool $is_active
 */
#[ObservedBy([EdtPriceTierObserver::class])]
class EdtPriceTier extends Model
{
    /** @use HasFactory<EdtPriceTierFactory> */
    use HasAuditFields, HasFactory, LogsActivity, SavesAtomically;

    protected $table = 'edt_price_tiers';

    protected $fillable = [
        'name',
        'min_boxes',
        'discount_pct',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'name' => Uppercase::class,
            'min_boxes' => 'integer',
            'discount_pct' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(EdtModule::LOG_NAME)
            ->logOnly(['name', 'min_boxes', 'discount_pct', 'is_active'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
