<?php

namespace App\Models\Edt;

use App\Casts\Uppercase;
use App\Support\Edt\EdtModule;
use App\Traits\HasAuditFields;
use Database\Factories\Edt\EdtSupplierFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;
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
 * Excepción: el correo va en minúsculas — la parte antes de la @ distingue
 * mayúsculas en algunos servidores y en mayúsculas podría no llegar.
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
    use HasAuditFields, HasFactory, LogsActivity;

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

    /**
     * El RTN se guarda solo con dígitos: acepta "0501-1990-123456" desde el
     * formulario y guarda "05011990123456". El CHECK de la tabla exige 14.
     */
    protected function rtn(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $digits = preg_replace('/\D/', '', (string) $value);

                return $digits === '' ? null : $digits;
            },
        );
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

    /**
     * Enmascara el RTN antes de guardar la bitácora.
     *
     * Regla del proyecto: nunca guardar un RTN completo en logs. Se conserva
     * que el RTN cambió y sus últimos 4 dígitos, suficiente para auditar.
     */
    public function tapActivity(Activity $activity, string $eventName): void
    {
        $activity->properties = $activity->properties->map(
            function (mixed $values): mixed {
                if (is_array($values) && ! empty($values['rtn'])) {
                    $values['rtn'] = self::maskRtn((string) $values['rtn']);
                }

                return $values;
            }
        );
    }

    /**
     * "05011990123456" → "**********3456".
     */
    public static function maskRtn(string $rtn): string
    {
        return str_repeat('*', max(strlen($rtn) - 4, 0)).substr($rtn, -4);
    }
}
