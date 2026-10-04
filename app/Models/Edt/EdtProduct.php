<?php

namespace App\Models\Edt;

use App\Casts\Uppercase;
use App\Enums\Edt\PriceChangeReason;
use App\Models\Edt\Concerns\SavesAtomically;
use App\Observers\Edt\EdtProductObserver;
use App\Services\Edt\EdtPriceQuote;
use App\Services\Edt\EdtPricingService;
use App\Support\Edt\EdtModule;
use App\Traits\HasAuditFields;
use Database\Factories\Edt\EdtProductFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Producto del catálogo del EDT. Sin inventario: solo lo necesario para
 * calcular precios (ver EdtPricingService).
 *
 * Los precios NO se guardan en esta tabla: priceQuote() los calcula al
 * momento. Cada cambio de un campo de PRICE_FIELDS escribe una fila en
 * edt_product_price_history (EdtProductObserver), en la misma transacción.
 *
 * Todo el texto va en MAYÚSCULAS (regla del EDT).
 *
 * Ojo: saveQuietly(), updateQuietly() y los UPDATE masivos por query
 * (EdtProduct::query()->update(...)) no disparan el observer y NO dejan
 * historial. Los cambios de precio van por el modelo o por
 * EdtProductPriceService.
 *
 * @property int $id
 * @property int $supplier_id
 * @property string $code
 * @property string $description
 * @property string|null $category
 * @property string|null $family
 * @property string|null $presentation
 * @property int $units_per_box
 * @property string $isv_pct
 * @property string $list_price
 * @property bool $is_active
 * @property-read EdtSupplier $supplier
 */
#[ObservedBy([EdtProductObserver::class])]
class EdtProduct extends Model
{
    /** @use HasFactory<EdtProductFactory> */
    use HasAuditFields, HasFactory, LogsActivity, SavesAtomically;

    protected $table = 'edt_products';

    /** Campos que mueven el precio: cambiar cualquiera escribe historial. */
    public const PRICE_FIELDS = ['supplier_id', 'list_price', 'units_per_box', 'isv_pct'];

    /** Tasas de ISV válidas (CHECK edt_products_isv_valid). */
    public const ISV_RATES = ['0' => 'EXENTO', '15' => '15%', '18' => '18%'];

    protected $fillable = [
        'supplier_id',
        'code',
        'description',
        'category',
        'family',
        'presentation',
        'units_per_box',
        'isv_pct',
        'list_price',
        'is_active',
    ];

    /** Motivo del próximo cambio de precio (no se guarda en la tabla). */
    private ?PriceChangeReason $pendingPriceReason = null;

    private ?string $pendingPriceNote = null;

    private ?string $quoteKey = null;

    private ?EdtPriceQuote $quote = null;

    protected function casts(): array
    {
        return [
            'code' => Uppercase::class,
            'description' => Uppercase::class,
            'category' => Uppercase::class,
            'family' => Uppercase::class,
            'presentation' => Uppercase::class,
            'units_per_box' => 'integer',
            'isv_pct' => 'decimal:2',
            'list_price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(EdtSupplier::class, 'supplier_id');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(EdtProductPriceHistory::class, 'product_id');
    }

    /**
     * Marca el motivo del próximo cambio de precio; lo lee el observer al
     * escribir el historial. Sin marcar, el motivo es CREACIÓN o EDICIÓN.
     */
    public function withPriceChangeReason(PriceChangeReason $reason, ?string $note = null): static
    {
        $this->pendingPriceReason = $reason;
        $this->pendingPriceNote = $note;

        return $this;
    }

    /**
     * Devuelve y limpia el motivo marcado.
     *
     * @return array{0: PriceChangeReason|null, 1: string|null}
     */
    public function pullPriceChangeReason(): array
    {
        $pending = [$this->pendingPriceReason, $this->pendingPriceNote];
        $this->pendingPriceReason = null;
        $this->pendingPriceNote = null;

        return $pending;
    }

    /**
     * Precios vigentes, calculados al momento. Se memorizan mientras no
     * cambien los datos de entrada, para que una tabla no los recalcule por
     * cada columna.
     */
    public function priceQuote(): EdtPriceQuote
    {
        $this->loadMissing('supplier:id,operation_discount_pct');

        $key = implode('|', [
            $this->list_price,
            $this->units_per_box,
            $this->isv_pct,
            $this->supplier?->operation_discount_pct,
        ]);

        if ($this->quote === null || $this->quoteKey !== $key) {
            $this->quote = app(EdtPricingService::class)->quoteForProduct($this);
            $this->quoteKey = $key;
        }

        return $this->quote;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(EdtModule::LOG_NAME)
            ->logOnly([
                'supplier_id',
                'code',
                'description',
                'category',
                'family',
                'presentation',
                'units_per_box',
                'isv_pct',
                'list_price',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
