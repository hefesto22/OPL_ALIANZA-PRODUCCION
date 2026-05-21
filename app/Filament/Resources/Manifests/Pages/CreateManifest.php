<?php

namespace App\Filament\Resources\Manifests\Pages;

use App\Filament\Resources\Manifests\ManifestResource;
use App\Filament\Resources\Manifests\Schemas\ManifestForm;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

/**
 * @deprecated A partir de 2026-05-20. Esta página implementaba el upload
 *             manual JSON desde Filament. Ya no está registrada en
 *             ManifestResource::getPages() — la ruta /admin/manifests/create
 *             devuelve 404 y el botón "+ Nuevo" no aparece en el listado
 *             (canCreate() devuelve false).
 *
 *             Todos los manifiestos llegan vía API de Jaremar. Si necesitas
 *             reactivar esta página, además de restaurarla en getPages()
 *             deberás agregar las validaciones del ManifestDateValidator
 *             en el flujo manual para no quedar más permisivo que el API.
 */
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
