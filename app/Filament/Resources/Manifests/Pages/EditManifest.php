<?php

namespace App\Filament\Resources\Manifests\Pages;

use App\Filament\Resources\Manifests\ManifestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditManifest extends EditRecord
{
    protected static string $resource = ManifestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn() => $this->record->isClosed()),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}