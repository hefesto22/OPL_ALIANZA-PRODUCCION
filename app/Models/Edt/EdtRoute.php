<?php

namespace App\Models\Edt;

use App\Casts\Uppercase;
use App\Models\Geo\Municipality;
use App\Models\Warehouse;
use App\Support\Edt\EdtModule;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\Edt\EdtRouteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Ruta del EDT: un conjunto de municipios de una bodega que atiende un
 * vendedor, con días de visita opcionales.
 *
 * Quién la tiene y desde cuándo vive en EdtRouteAssignment (historial);
 * se cambia con EdtRouteAssignmentService, nunca editando a mano.
 *
 * @property int $id
 * @property int $warehouse_id
 * @property string $name
 * @property list<int> $visit_days días ISO (1 = lunes … 7 = domingo); vacío = cualquier día
 * @property bool $is_active
 */
class EdtRoute extends Model
{
    /** @use HasFactory<EdtRouteFactory> */
    use HasAuditFields, HasFactory, LogsActivity;

    /** Días ISO-8601 (Carbon::dayOfWeekIso) => etiqueta. */
    public const WEEKDAYS = [
        1 => 'LUNES',
        2 => 'MARTES',
        3 => 'MIÉRCOLES',
        4 => 'JUEVES',
        5 => 'VIERNES',
        6 => 'SÁBADO',
        7 => 'DOMINGO',
    ];

    protected $table = 'edt_routes';

    protected $fillable = [
        'warehouse_id',
        'name',
        'visit_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'name' => Uppercase::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Días de visita como lista ordenada y sin repetir de enteros 1…7. Se
     * aceptan strings ("1", "3") porque así llegan de un CheckboxList.
     */
    protected function visitDays(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : array_values(array_map('intval', json_decode($value, true) ?: [])),
            set: function (mixed $value): string {
                $days = collect(is_array($value) ? $value : [])
                    ->map(fn ($day): int => (int) $day)
                    ->filter(fn (int $day): bool => isset(self::WEEKDAYS[$day]))
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                return json_encode($days);
            },
        );
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function municipalities(): BelongsToMany
    {
        return $this->belongsToMany(Municipality::class, 'edt_route_municipality', 'route_id', 'municipality_id')
            ->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EdtRouteAssignment::class, 'route_id');
    }

    /**
     * La asignación vigente hoy (si hay).
     */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(EdtRouteAssignment::class, 'route_id')
            ->ofMany(['starts_on' => 'max'], fn (Builder $query) => $query->currentOn());
    }

    /**
     * ¿Se visita esta ruta en esa fecha? Sin días configurados, cualquier día.
     */
    public function visitsOn(CarbonInterface $date): bool
    {
        return $this->visit_days === [] || in_array($date->dayOfWeekIso, $this->visit_days, true);
    }

    /**
     * "LUNES, JUEVES" o "CUALQUIER DÍA".
     */
    public function visitDaysLabel(): string
    {
        if ($this->visit_days === []) {
            return 'CUALQUIER DÍA';
        }

        return collect($this->visit_days)->map(fn (int $day): string => self::WEEKDAYS[$day])->implode(', ');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(EdtModule::LOG_NAME)
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
