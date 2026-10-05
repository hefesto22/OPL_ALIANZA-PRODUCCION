<?php

namespace App\Filament\Resources\Edt\Sellers\Pages;

use App\Filament\Resources\Edt\Sellers\EdtSellerResource;
use App\Models\Edt\EdtSeller;
use App\Services\Edt\EdtRuleException;
use App\Services\Edt\EdtSellerService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Cambios del vendedor y de su usuario (EdtSellerService). Sin borrar: un
 * vendedor se desactiva y su historial de rutas se conserva.
 */
class EditEdtSeller extends EditRecord
{
    protected static string $resource = EdtSellerResource::class;

    /**
     * El correo vive en el usuario del vendedor, no en edt_sellers.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var EdtSeller $seller */
        $seller = $this->getRecord();
        $data['email'] = $seller->user?->email;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            /** @var EdtSeller $record */
            return app(EdtSellerService::class)->update($record, $data);
        } catch (EdtRuleException $e) {
            Notification::make()->title('No se pudo guardar')->body($e->getMessage())->danger()->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
