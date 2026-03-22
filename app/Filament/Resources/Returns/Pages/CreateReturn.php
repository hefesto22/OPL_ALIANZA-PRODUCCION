<?php

namespace App\Filament\Resources\Returns\Pages;

use App\Filament\Resources\Returns\ReturnResource;
use App\Filament\Resources\Returns\Schemas\ReturnForm;
use App\Services\ReturnService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CreateReturn extends CreateRecord
{
    protected static string $resource = ReturnResource::class;

    protected static ?string $title = 'Nueva Devolución';

    public function form(Schema $schema): Schema
    {
        return ReturnForm::make($schema);
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $data['created_by'] = Auth::id();

        try {
            return app(ReturnService::class)->createReturn($data);
        } catch (ValidationException $e) {
            // ValidationException se muestra nativamente en el formulario de Filament.
            throw $e;
        } catch (\RuntimeException $e) {
            // Errores de negocio (manifiesto cerrado, etc.) → notificación visible.
            Notification::make()
                ->title('No se pudo registrar la devolución')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
