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
            $query->whereIn($column, self::getWarehouseIds());
        }

        return $query;
    }

    /**
     * Aplica el filtro de bodega a través de una relación.
     * Úsalo en modelos sin warehouse_id directo (p.ej. Deposit filtra via manifest.warehouse_id).
     */
    public static function applyViaRelation(
        Builder $query,
        string $relation,
        string $column = 'warehouse_id'
    ): Builder {
        if (self::isScoped()) {
            $warehouseIds = self::getWarehouseIds();
            $query->whereHas($relation, function (Builder $q) use ($warehouseIds, $column) {
                $q->whereIn($column, $warehouseIds);
            });
        }

        return $query;
    }

    /**
     * Genera una clave de caché única por conjunto de bodegas.
     * Esto evita que un usuario de bodega vea la caché de otro usuario.
     *
     * Ejemplo: WarehouseScope::cacheKey('dashboard:stats')
     *   → 'dashboard:stats:global'          (admin / super_admin)
     *   → 'dashboard:stats:warehouse:3'     (usuario de la bodega 3)
     *   → 'dashboard:stats:warehouse:1-3'   (usuario de las bodegas 1 y 3)
     */
    public static function cacheKey(string $base): string
    {
        $warehouseIds = self::getWarehouseIds();

        if ($warehouseIds === []) {
            return "{$base}:global";
        }

        sort($warehouseIds); // estable: [3,1] y [1,3] dan la misma clave

        return "{$base}:warehouse:".implode('-', $warehouseIds);
    }

    /**
     * Retorna true si el usuario autenticado está limitado a una o más bodegas.
     */
    public static function isScoped(): bool
    {
        $user = Auth::user();

        return $user && $user->isWarehouseUser();
    }

    /**
     * Retorna los IDs de las bodegas del usuario autenticado.
     * Arreglo vacío = usuario global (ve todas las bodegas).
     *
     * @return array<int, int>
     */
    public static function getWarehouseIds(): array
    {
        return Auth::user()?->warehouseIds() ?? [];
    }
}
