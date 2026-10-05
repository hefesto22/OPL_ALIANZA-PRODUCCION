<?php

namespace App\Filament\Resources\Edt\Products\Pages;

use App\Filament\Resources\Edt\Products\EdtProductResource;
use App\Filament\Resources\Edt\Products\Support\PriceInputFields;
use Filament\Resources\Pages\CreateRecord;

/**
 * Al crear, el observer escribe la primera fila del historial (CREACIÓN).
 */
class CreateEdtProduct extends CreateRecord
{
    protected static string $resource = EdtProductResource::class;

    /**
     * El formulario trae el precio como se escribió (con o sin ISV); se
     * guarda la lista sin ISV.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['list_price'] = PriceInputFields::listPriceFromState($data);

        unset($data[PriceInputFields::PRICE], $data[PriceInputFields::INCLUDES_ISV]);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
