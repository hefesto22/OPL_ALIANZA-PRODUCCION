<?php

namespace App\Models\Edt;

use App\Enums\Edt\EdtOrderStatus;
use App\Enums\Edt\EdtPaymentType;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Edt\EdtModule;
use App\Traits\HasAuditFields;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Preventa del EDT: la toma el vendedor en ruta, la revisa la bodega y,
 * aprobada, pasa a facturación.
 *
 * No se crea ni se cambia a mano: todo pasa por EdtOrderService, que
 * calcula precios y totales en el servidor (con lock) y aplica las reglas
 * de cada estado. Las líneas guardan el precio del día de la preventa.
 *
 * @property int $id
 * @property string|null $number
 * @property int $warehouse_id
 * @property int $seller_id
 * @property int $client_id
 * @property int|null $route_id
 * @property EdtOrderStatus $status
 * @property EdtPaymentType $payment_type
 * @property \Illuminate\Support\Carbon $order_date
 * @property string|null $note
 * @property string $total
 * @property string $requested_total
 * @property int $lines_count
 */
class EdtOrder extends Model
{
    use HasAuditFields, LogsActivity;

    protected $table = 'edt_orders';

    protected $fillable = [
        'warehouse_id',
        'seller_id',
        'client_id',
        'route_id',
        'status',
        'payment_type',
        'order_date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'status' => EdtOrderStatus::class,
            'payment_type' => EdtPaymentType::class,
            'order_date' => 'date',
            'exempt_total' => 'decimal:2',
            'taxable_15' => 'decimal:2',
            'isv_15' => 'decimal:2',
            'taxable_18' => 'decimal:2',
            'isv_18' => 'decimal:2',
            'total' => 'decimal:2',
            'requested_total' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'lines_count' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(EdtSeller::class, 'seller_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(EdtClient::class, 'client_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(EdtRoute::class, 'route_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EdtOrderLine::class, 'order_id');
    }

    /** ¿La bodega dejó menos de lo que pidió el vendedor? */
    public function isReduced(): bool
    {
        return BigDecimal::of((string) $this->total)->isLessThan((string) $this->requested_total);
    }

    /**
     * Bitácora del encabezado. Las líneas no se registran una por una: el
     * servicio deja una entrada al enviar, al ajustar (con lo que cambió),
     * al aprobar, rechazar y anular.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(EdtModule::LOG_NAME)
            ->logOnly(['number', 'status', 'payment_type', 'note', 'total'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
