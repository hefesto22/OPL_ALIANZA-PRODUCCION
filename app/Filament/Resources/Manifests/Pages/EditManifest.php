<?php

namespace App\Filament\Resources\Manifests\Pages;

use App\Filament\Resources\Manifests\ManifestResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditManifest extends EditRecord
{
    protected static string $resource = ManifestResource::class;

    /**
     * Solo super_admin puede acceder a la página de edición.
     * Si alguien intenta acceder por URL, se le deniega.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasRole('super_admin')) {
            abort(403, 'No tienes permiso para editar manifiestos.');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(function (): bool {
                    /** @var User $user */
                    $user = Auth::user();
                    return $this->record->isClosed() || !$user->hasAnyRole(['super_admin', 'admin']);
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}