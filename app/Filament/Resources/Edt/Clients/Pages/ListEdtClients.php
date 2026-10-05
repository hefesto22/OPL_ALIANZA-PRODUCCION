<?php

namespace App\Filament\Resources\Edt\Clients\Pages;

use App\Filament\Resources\Edt\Clients\EdtClientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEdtClients extends ListRecords
{
    protected static string $resource = EdtClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
