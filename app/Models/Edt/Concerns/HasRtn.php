<?php

namespace App\Models\Edt\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Columna `rtn` de los modelos del EDT (proveedores, clientes).
 *
 * - Se guarda solo con dígitos: acepta "0501-1990-123456" desde el
 *   formulario y guarda "05011990123456". El CHECK de cada tabla exige 14.
 * - La bitácora (Spatie ActivityLog) nunca guarda el RTN completo: regla
 *   del proyecto. Se conserva que cambió y sus últimos 4 dígitos.
 *
 * El modelo que lo use debe usar también LogsActivity.
 */
trait HasRtn
{
    protected function rtn(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $digits = preg_replace('/\D/', '', (string) $value);

                return $digits === '' ? null : $digits;
            },
        );
    }

    /**
     * Enmascara el RTN antes de guardar la bitácora.
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
