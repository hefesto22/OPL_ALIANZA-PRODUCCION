<?php

namespace App\Filament\Resources\Edt\Clients\Pages;

use App\Filament\Resources\Edt\Clients\EdtClientResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEdtClient extends CreateRecord
{
    protected static string $resource = EdtClientResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
