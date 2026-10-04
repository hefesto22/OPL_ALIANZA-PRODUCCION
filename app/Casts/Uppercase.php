<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Guarda el texto en MAYÚSCULAS, sin espacios en los extremos y con los
 * espacios repetidos reducidos a uno ("  Del  monte " → "DEL MONTE").
 *
 * Regla del módulo EDT (Mauricio, 2026-10-04): todo el texto de los
 * catálogos sale en mayúsculas, igual que el catálogo de Jaremar
 * ("GRANOLA ALMENDRA GRAN DIA 380G"). Se normaliza al GUARDAR, no solo al
 * mostrar, para que la tabla, las búsquedas, los PDF y la factura impresa
 * usen exactamente el mismo texto.
 *
 * mb_strtoupper respeta tildes y eñes: "Copán" → "COPÁN", "Piña" → "PIÑA".
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class Uppercase implements CastsAttributes
{
    /**
     * Normalización compartida: la usa el cast al guardar y el formulario
     * al validar (para que la regla unique compare lo mismo que se guarda).
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $collapsed = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $collapsed === '' ? null : mb_strtoupper($collapsed, 'UTF-8');
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::normalize($value === null ? null : (string) $value);
    }
}
