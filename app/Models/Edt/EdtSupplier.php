<?php

namespace App\Models\Edt;

use App\Casts\Uppercase;
use App\Models\Edt\Concerns\HasRtn;
use App\Models\Edt\Concerns\SavesAtomically;
use App\Support\Edt\EdtModule;
use App\Traits\HasAuditFields;
use Database\Factories\Edt\EdtSupplierFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Proveedor del módulo EDT.
 *
 * No confundir con App\Models\Supplier: ese es el proveedor de la API de
 * Jaremar. El prefijo Edt en el nombre de la clase es deliberado: Shield arma
 * el nombre del permiso con el nombre corto de la clase (class_basename), así
 * que un "Supplier" del EDT chocaría con el de Jaremar. Así queda
 * ViewAny:EdtSupplier, distinguible en la pantalla de Roles.
 *
 * Todo el texto se guarda en MAYÚSCULAS (cast Uppercase), regla del EDT.
 * El RTN (solo dígitos, enmascarado en la bitácora) lo maneja HasRtn.
 * Excepción: el correo va en minúsculas — la parte antes de la @ distingue
 * mayúsculas en algunos servidores y en mayúsculas podría no llegar.
 *
 * operation_discount_pct (el 15%) ya no mueve costos: cada producto guarda
 * su costo por caja. Solo se usa para SUGERIR el costo al escribir un precio
 * y para calcularlo al importar el Excel (EdtPricingService::boxCostFromList).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $rtn
 * @property string $operation_discount_pct
 * @property bool $is_active
 */
class EdtSupplier extends Model
{
    /** @use HasFactory<EdtSupplierFactory> */
    use HasAuditFields, HasFactory, HasRtn, LogsActivity, SavesAtomically;

    protected $table = 'edt_suppliers';

    protected $fillable = [
        'code',
        'name',
        'rtn',
        'contact_name',
        'phone',
        'email',
        'address',
        'operation_discount_pct',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // Uppercase también deja el código sin espacios en los extremos,
            // así "cbc " y "CBC" no terminan como dos proveedores distintos.
            'code' => Uppercase::class,
            'name' => Uppercase::class,
            'contact_name' => Uppercase::class,
            'address' => Uppercase::class,
            'operation_discount_pct' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * El correo se guarda en minúsculas y sin espacios en los extremos.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $email = mb_strtolower(trim((string) $value));

                return $email === '' ? null : $email;
            },
        );
    }

    public function products(): HasMany
    {
        return $this->hasMany(EdtProduct::class, 'supplier_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName(EdtModule::LOG_NAME)
            ->logOnly([
                'code',
                'name',
                'rtn',
                'contact_name',
                'phone',
                'email',
                'address',
                'operation_discount_pct',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
