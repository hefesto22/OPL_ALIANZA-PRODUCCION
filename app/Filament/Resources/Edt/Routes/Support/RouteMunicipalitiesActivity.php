<?php

namespace App\Filament\Resources\Edt\Routes\Support;

use App\Models\Edt\EdtRoute;
use App\Support\Edt\EdtModule;

/**
 * Bitácora de los municipios de una ruta.
 *
 * El pivote edt_route_municipality no pasa por LogsActivity (Filament lo
 * sincroniza directo), y cambiar los municipios cambia qué clientes ve el
 * vendedor: se registra aquí a mano, solo si cambiaron.
 */
final class RouteMunicipalitiesActivity
{
    /**
     * @return list<string>
     */
    public static function names(EdtRoute $route): array
    {
        return $route->municipalities()->orderBy('hn_municipalities.name')->pluck('hn_municipalities.name')->all();
    }

    /**
     * @param  list<string>  $before
     */
    public static function logIfChanged(EdtRoute $route, array $before): void
    {
        $after = self::names($route);

        if ($before === $after) {
            return;
        }

        activity(EdtModule::LOG_NAME)
            ->performedOn($route)
            ->event('updated')
            ->withProperties(['old' => ['municipios' => $before], 'attributes' => ['municipios' => $after]])
            ->log('Municipios de la ruta');
    }
}
