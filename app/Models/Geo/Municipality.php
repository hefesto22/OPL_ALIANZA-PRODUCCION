<?php

namespace App\Models\Geo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Municipio de Honduras (catálogo oficial, código INE de 4 dígitos: los dos
 * primeros son el departamento).
 *
 * Solo lectura para la app: los 298 registros los crea la migración
 * create_hn_geography_tables.
 *
 * @property int $id
 * @property int $department_id
 * @property string $code
 * @property string $name
 */
class Municipality extends Model
{
    protected $table = 'hn_municipalities';

    protected $guarded = ['*'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Opciones para un Select: los municipios de un departamento, por nombre.
     *
     * @return array<int, string>
     */
    public static function optionsFor(int|string|null $departmentId): array
    {
        if (blank($departmentId)) {
            return [];
        }

        return static::query()
            ->where('department_id', $departmentId)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
