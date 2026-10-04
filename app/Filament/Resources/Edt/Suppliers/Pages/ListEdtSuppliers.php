<?php

namespace App\Filament\Resources\Edt\Suppliers\Pages;

use App\Filament\Resources\Edt\Suppliers\EdtSupplierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEdtSuppliers extends ListRecords
{
    protected static string $resource = EdtSupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
