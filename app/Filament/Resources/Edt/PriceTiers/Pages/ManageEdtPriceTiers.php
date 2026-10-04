<?php

namespace App\Filament\Resources\Edt\PriceTiers\Pages;

use App\Filament\Resources\Edt\PriceTiers\EdtPriceTierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEdtPriceTiers extends ManageRecords
{
    protected static string $resource = EdtPriceTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
