<?php

namespace App\Filament\Resources\Edt\Clients\Pages;

use App\Filament\Resources\Edt\Clients\EdtClientResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEdtClient extends EditRecord
{
    protected static string $resource = EdtClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Borrar es para un cliente creado por error; el de uso normal es
            // desactivarlo. EdtClientPolicy decide si el botón aparece.
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
