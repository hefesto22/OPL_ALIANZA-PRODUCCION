<?php

namespace App\Models\Geo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Departamento de Honduras (catálogo oficial, código INE de 2 dígitos).
 *
 * Solo lectura para la app: los 18 registros los crea la migración
 * create_hn_geography_tables. Una corrección se hace con otra migración.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Department extends Model
{
    protected $table = 'hn_departments';

    protected $guarded = ['*'];

    public function municipalities(): HasMany
    {
        return $this->hasMany(Municipality::class);
    }

    /**
     * Opciones para un Select: [id => nombre], ordenadas por nombre.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
