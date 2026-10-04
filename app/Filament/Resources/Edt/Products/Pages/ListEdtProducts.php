<?php

namespace App\Filament\Resources\Edt\Products\Pages;

use App\Filament\Resources\Edt\Products\EdtProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEdtProducts extends ListRecords
{
    protected static string $resource = EdtProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
