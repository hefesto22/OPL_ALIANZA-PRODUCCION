<?php

namespace App\Filament\Resources\Edt\Sellers\Pages;

use App\Filament\Resources\Edt\Sellers\EdtSellerResource;
use App\Services\Edt\EdtSellerService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Alta de vendedor: crea en la misma transacción el vendedor y su usuario
 * (EdtSellerService).
 */
class CreateEdtSeller extends CreateRecord
{
    protected static string $resource = EdtSellerResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(EdtSellerService::class)->create($data);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
