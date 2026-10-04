<?php

namespace App\Filament\Resources\Edt\Suppliers\Pages;

use App\Filament\Resources\Edt\Suppliers\EdtSupplierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEdtSupplier extends EditRecord
{
    protected static string $resource = EdtSupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Borrar es para un proveedor creado por error. El de uso normal
            // es desactivarlo. Cuando existan productos (fase 2), la FK con
            // restrictOnDelete impedirá borrar uno que tenga catálogo.
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
