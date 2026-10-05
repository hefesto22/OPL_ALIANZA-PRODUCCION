<?php

namespace App\Models\Edt;

use App\Casts\Uppercase;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Edt\EdtModule;
use App\Traits\HasAuditFields;
use Carbon\CarbonInterface;
use Database\Factories\Edt\EdtSellerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Vendedor de ruta del EDT.
 *
 * Tiene su propio usuario (user_id) con el rol vendedor_edt; se crea y se
 * actualiza junto con él en EdtSellerService. Es de una sola bodega y solo
 * puede tener rutas de esa bodega.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $phone
 * @property int $warehouse_id
 * @property int $user_id
 * @property bool $is_active
 */
class EdtSeller extends Model
{
    /** @use HasFactory<EdtSellerFactory> */
    use HasAuditFields, HasFactory, LogsActivity;

    protected $table = 'edt_sellers';

    protected $fillable = [
        'code',
        'name',
        'phone',
        'warehouse_id',
        'user_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'code' => Uppercase::class,
            'name' => Uppercase::class,
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EdtRouteAssignment::class, 'seller_id');
    }

    /**
     * Asignaciones vigentes en una fecha (hoy por defecto).
     */
    public function currentAssignments(?CarbonInterface $on = null): HasMany
    {
        return $this->assignments()->currentOn($on);
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
