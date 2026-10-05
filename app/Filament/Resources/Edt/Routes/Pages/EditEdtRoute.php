<?php

namespace App\Filament\Resources\Edt\Routes\Pages;

use App\Filament\Resources\Edt\Routes\Actions\RouteAssignmentActions;
use App\Filament\Resources\Edt\Routes\EdtRouteResource;
use App\Filament\Resources\Edt\Routes\Support\RouteMunicipalitiesActivity;
use App\Models\Edt\EdtRoute;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Edición de la ruta (nombre, municipios, días) y, en el encabezado, el
 * manejo de su vendedor. Debajo va el historial de vendedores.
 */
class EditEdtRoute extends EditRecord
{
    protected static string $resource = EdtRouteResource::class;

    /** @var list<string> */
    private array $municipalitiesBefore = [];

    protected function getHeaderActions(): array
    {
        $refresh = fn () => $this->dispatch('refresh-route-assignments');

        return [
            RouteAssignmentActions::assign()->after($refresh),
            RouteAssignmentActions::release()->after($refresh),
            RouteAssignmentActions::cancelScheduled()->after($refresh),
            // Solo una ruta que nunca tuvo vendedor (EdtRoutePolicy::delete).
            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        /** @var EdtRoute $route */
        $route = $this->getRecord();

        $this->municipalitiesBefore = RouteMunicipalitiesActivity::names($route);
    }

    protected function afterSave(): void
    {
        /** @var EdtRoute $route */
        $route = $this->getRecord();

        RouteMunicipalitiesActivity::logIfChanged($route, $this->municipalitiesBefore);
    }
}
