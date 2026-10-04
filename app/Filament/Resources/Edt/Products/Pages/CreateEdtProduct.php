<?php

namespace App\Filament\Resources\Edt\Products\Pages;

use App\Filament\Resources\Edt\Products\EdtProductResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Al crear, el observer escribe la primera fila del historial (CREACIÓN).
 */
class CreateEdtProduct extends CreateRecord
{
    protected static string $resource = EdtProductResource::class;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
