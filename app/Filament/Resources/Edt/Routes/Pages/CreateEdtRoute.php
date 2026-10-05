<?php

namespace App\Filament\Resources\Edt\Routes\Pages;

use App\Filament\Resources\Edt\Routes\EdtRouteResource;
use App\Filament\Resources\Edt\Routes\Support\RouteMunicipalitiesActivity;
use App\Models\Edt\EdtRoute;
use Filament\Resources\Pages\CreateRecord;

/**
 * Alta de ruta. Al guardar va a la edición, que es donde se le asigna
 * vendedor (con fecha) y se ve su historial.
 */
class CreateEdtRoute extends CreateRecord
{
    protected static string $resource = EdtRouteResource::class;

    protected function afterCreate(): void
    {
        /** @var EdtRoute $route */
        $route = $this->getRecord();

        RouteMunicipalitiesActivity::logIfChanged($route, before: []);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
