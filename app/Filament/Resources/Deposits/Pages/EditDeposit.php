<?php

namespace App\Filament\Resources\Deposits\Pages;

use App\Filament\Resources\Deposits\DepositResource;
use App\Services\DepositService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditDeposit extends EditRecord
{
    protected static string $resource = DepositResource::class;

    protected static ?string $title = 'Editar Depósito';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->manifest->isClosed()) {
            Notification::make()
                ->title('Manifiesto cerrado')
                ->body('No se puede editar un depósito de un manifiesto cerrado.')
                ->warning()
                ->send();

            $this->redirect(
                $this->getResource()::getUrl('view', ['record' => $this->record])
            );
        }
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        /** @var \App\Models\Deposit $record */
        return app(DepositService::class)->updateDeposit($record, $data, Auth::id());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
