<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Utilidad centralizada para filtrar consultas Eloquent por bodega.
 *
 * Reglas:
 *  - Usuarios globales (warehouse_id = null): ven TODO el sistema.
 *  - Usuarios de bodega (warehouse_id = X):   ven solo los datos de su bodega.
 *
 * Uso en Resources:
 *   WarehouseScope::apply($query);                          // modelo con warehouse_id directo
 *   WarehouseScope::applyViaRelation($query, 'manifest');   // modelo sin warehouse_id (p.ej. Deposit)
 *
 * Uso en Widgets (para cache por bodega):
 *   $key = WarehouseScope::cacheKey('dashboard:stats');
 */
class WarehouseScope
{
    /**
     * Aplica el filtro de bodega directamente sobre el campo `warehouse_id` del modelo.
     * Úsalo en modelos que tienen warehouse_id como columna propia (InvoiceReturn, Manifest, etc.).
     */
    public static function apply(Builder $query, string $column = 'warehouse_id'): Builder
    {
        if (self::isScoped()) {
            $query->where($column, self::getWarehouseId());
        }

        return $query;
    }

    /**
     * Aplica el filtro de bodega a través de una relación.
     * Úsalo en modelos sin warehouse_id directo (p.ej. Deposit filtra via manifest.warehouse_id).
     */
    public static function applyViaRelation(
        Builder $query,
        string  $relation,
        string  $column = 'warehouse_id'
    ): Builder {
        if (self::isScoped()) {
            $warehouseId = self::getWarehouseId();
            $query->whereHas($relation, function (Builder $q) use ($warehouseId, $column) {
                $q->where($column, $warehouseId);
            });
        }

        return $query;
    }

    /**
     * Genera una clave de caché única por bodega.
     * Esto evita que un usuario de bodega vea la caché de otro usuario.
     *
     * Ejemplo: WarehouseScope::cacheKey('dashboard:stats')
     *   → 'dashboard:stats:global'        (admin / super_admin)
     *   → 'dashboard:stats:warehouse:3'   (encargado de bodega 3)
     */
    public static function cacheKey(string $base): string
    {
        $warehouseId = self::getWarehouseId();

        return $warehouseId
            ? "{$base}:warehouse:{$warehouseId}"
            : "{$base}:global";
    }

    /**
     * Retorna true si el usuario autenticado está limitado a una bodega.
     */
    public static function isScoped(): bool
    {
        $user = Auth::user();
        return $user && $user->isWarehouseUser();
    }

    /**
     * Retorna el warehouse_id del usuario autenticado, o null si es global.
     */
    public static function getWarehouseId(): ?int
    {
        return Auth::user()?->warehouse_id;
    }
}
