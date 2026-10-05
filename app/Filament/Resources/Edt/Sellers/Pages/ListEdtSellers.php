<?php

namespace App\Filament\Resources\Edt\Sellers\Pages;

use App\Filament\Resources\Edt\Sellers\EdtSellerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEdtSellers extends ListRecords
{
    protected static string $resource = EdtSellerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
