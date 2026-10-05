<?php

namespace App\Filament\Resources\Edt\Routes\Pages;

use App\Filament\Resources\Edt\Routes\EdtRouteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEdtRoutes extends ListRecords
{
    protected static string $resource = EdtRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
