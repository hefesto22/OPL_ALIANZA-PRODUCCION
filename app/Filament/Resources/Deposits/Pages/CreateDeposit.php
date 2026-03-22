<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Models\Manifest;
use App\Services\DepositService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateDeposit extends CreateRecord
{
    protected static string $resource = DepositResource::class;

    protected static ?string $title = 'Registrar Depósito';

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $manifest = Manifest::findOrFail($data['manifest_id']);

        if ($manifest->isClosed()) {
            Notification::make()
                ->title('Manifiesto cerrado')
                ->body('No se puede registrar un depósito en un manifiesto cerrado.')
                ->warning()
                ->send();

            $this->halt();
        }

        return app(DepositService::class)->createDeposit($manifest, $data, Auth::id());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}