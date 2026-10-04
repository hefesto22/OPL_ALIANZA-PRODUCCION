<?php

namespace App\Filament\Resources\Edt\Suppliers\Pages;

use App\Filament\Resources\Edt\Suppliers\EdtSupplierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEdtSupplier extends CreateRecord
{
    protected static string $resource = EdtSupplierResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
