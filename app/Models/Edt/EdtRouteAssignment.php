<?php

namespace App\Models\Edt;

use App\Models\Warehouse;
use App\Support\Edt\EdtModule;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Qué vendedor tiene una ruta, desde (starts_on) y hasta (ends_on, NULL =
 * sigue vigente) cuándo. Es historial: se crea y se cierra solo desde
 * EdtRouteAssignmentService.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property int $route_id
 * @property int $seller_id
 * @property \Illuminate\Support\Carbon $starts_on
 * @property \Illuminate\Support\Carbon|null $ends_on
 */
class EdtRouteAssignment extends Model
{
    use HasAuditFields, LogsActivity;

    public const STATUS_CURRENT = 'VIGENTE';

    public const STATUS_SCHEDULED = 'PROGRAMADA';

    public const STATUS_ENDED = 'TERMINADA';

    protected $table = 'edt_route_assignments';

    protected $fillable = [
        'warehouse_id',
        'route_id',
        'seller_id',
        'starts_on',
        'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(EdtRoute::class, 'route_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(EdtSeller::class, 'seller_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Asignaciones vigentes en una fecha (hoy por defecto): ya empezaron y
     * no han terminado.
     */
    public function scopeCurrentOn(Builder $query, ?CarbonInterface $on = null): void
    {
        $date = ($on ?? today())->toDateString();

        $query->where('starts_on', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $date));
    }

    public function statusOn(?CarbonInterface $on = null): string
    {
        $date = ($on ?? today())->copy()->startOfDay();

        return match (true) {
            $this->starts_on->gt($date) => self::STATUS_SCHEDULED,
            $this->ends_on !== null && $this->ends_on->lt($date) => self::STATUS_ENDED,
            default => self::STATUS_CURRENT,
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(EdtModule::LOG_NAME)
            ->logOnly(['route_id', 'seller_id', 'starts_on', 'ends_on'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
