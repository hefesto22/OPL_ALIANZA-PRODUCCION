<?php

namespace App\Models\Edt;

use App\Casts\Uppercase;
use App\Models\Edt\Concerns\HasRtn;
use App\Models\Geo\Department;
use App\Models\Geo\Municipality;
use App\Models\Warehouse;
use App\Observers\Edt\EdtClientObserver;
use App\Support\Edt\EdtModule;
use App\Traits\HasAuditFields;
use Database\Factories\Edt\EdtClientFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Cliente del módulo EDT.
 *
 * Nada que ver con los clientes de las facturas de Jaremar: el EDT arranca
 * sus clientes desde cero, en su propia tabla.
 *
 * - Pertenece a una bodega (warehouse_id): la que le factura. Los usuarios
 *   de bodega solo ven y tocan los clientes de sus bodegas.
 * - Su zona es departamento + municipio del catálogo oficial (App\Models\Geo).
 * - Código: si se deja vacío, EdtClientObserver asigna el siguiente
 *   (EdtClientCodeGenerator: C-000001…).
 * - Crédito opcional: sin crédito, límite y días quedan vacíos (el observer
 *   los limpia y un CHECK de la tabla lo exige).
 * - Texto en MAYÚSCULAS (cast Uppercase), regla del EDT. El RTN lo maneja
 *   HasRtn (solo dígitos, enmascarado en la bitácora).
 *
 * @property int $id
 * @property string $code
 * @property int $warehouse_id
 * @property string $name
 * @property string|null $business_name
 * @property string|null $business_type
 * @property string|null $rtn
 * @property string|null $phone
 * @property int $department_id
 * @property int $municipality_id
 * @property string|null $neighborhood
 * @property string|null $address
 * @property bool $credit_enabled
 * @property string|null $credit_limit
 * @property int|null $credit_days
 * @property bool $is_active
 */
#[ObservedBy([EdtClientObserver::class])]
class EdtClient extends Model
{
    /** @use HasFactory<EdtClientFactory> */
    use HasAuditFields, HasFactory, HasRtn, LogsActivity;

    protected $table = 'edt_clients';

    protected $fillable = [
        'code',
        'warehouse_id',
        'name',
        'business_name',
        'business_type',
        'rtn',
        'phone',
        'department_id',
        'municipality_id',
        'neighborhood',
        'address',
        'credit_enabled',
        'credit_limit',
        'credit_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'code' => Uppercase::class,
            'name' => Uppercase::class,
            'business_name' => Uppercase::class,
            'business_type' => Uppercase::class,
            'neighborhood' => Uppercase::class,
            'address' => Uppercase::class,
            'credit_enabled' => 'boolean',
            'credit_limit' => 'decimal:2',
            'credit_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
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
