<?php

namespace App\Filament\Resources\Manifests\Pages;

use App\Filament\Resources\Manifests\ManifestResource;
use App\Filament\Resources\Manifests\Schemas\ManifestForm;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateManifest extends CreateRecord
{
    protected static string $resource = ManifestResource::class;

    protected static ?string $title = 'Subir Manifiesto';

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $file = $data['json_file'] ?? null;

        if (! $file) {
            $this->halt();
        }

        $path = storage_path('app/private/'.(is_array($file) ? $file[0] : $file));

        $success = ManifestForm::processUpload($path, Auth::id());

        if (! $success) {
            $this->halt();
        }

        return new \App\Models\Manifest;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null;
    }
}
