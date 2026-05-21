<?php

namespace App\Filament\Resources\Manifests\Schemas;

use App\Jobs\ProcessManifestImport;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * @deprecated A partir de 2026-05-20, todos los manifiestos llegan vía API
 *             de Jaremar (POST /api/v1/facturas/insertar). El upload manual
 *             desde Filament queda inactivo — el FileUpload se removió del
 *             schema y el botón "Crear Manifiesto" del listado fue
 *             deshabilitado en ManifestResource::canCreate().
 *
 *             El código queda en git para restauración rápida si alguna vez
 *             se necesita una "puerta de emergencia" (Jaremar caído por
 *             días, lote especial, etc.). Para reactivar:
 *               1. Restaurar el FileUpload en configure()
 *               2. Quitar canCreate() del Resource
 *               3. Agregar las validaciones del ManifestDateValidator
 *                  ANTES de dispatch() del Job (sino el flujo manual
 *                  sería MÁS permisivo que el flujo API).
 *
 *             Servicios relacionados también marcados @deprecated:
 *               - App\Services\JsonValidatorService
 *               - App\Services\ManifestImporterService
 *               - App\Jobs\ProcessManifestImport
 */
class ManifestForm
{
    public static function configure(Schema $schema): Schema
    {
        // Schema vacío: no hay campos que el usuario pueda editar/crear
        // manualmente. Los manifiestos son inmutables desde el panel —
        // se crean automáticamente desde el API de Jaremar y se cierran
        // mediante acciones dedicadas (CloseManifestAction).
        return $schema->components([]);
    }

    /**
     * @deprecated Ver docblock de la clase. No invocar desde código nuevo.
     */
    public static function processUpload(
        string $filePath,
        int $userId,
        string $fileName = 'manifiesto.json'
    ): bool {
        $validator = resolve('App\Services\JsonValidatorService');
        $content = file_get_contents($filePath);

        if ($content === false) {
            Notification::make()
                ->title('Error al leer el archivo')
                ->body('No se pudo leer el archivo subido.')
                ->danger()
                ->send();

            return false;
        }

        if (! $validator->validate($content)) {
            Notification::make()
                ->title('JSON inválido')
                ->body($validator->getFirstError())
                ->danger()
                ->send();

            return false;
        }

        if ($validator->isDuplicate($content)) {
            $number = $validator->getManifestNumber($content);
            Notification::make()
                ->title('Manifiesto duplicado')
                ->body("El manifiesto #{$number} ya fue importado anteriormente.")
                ->warning()
                ->send();

            return false;
        }

        $storedPath = 'manifests/pending/'.basename($filePath);
        Storage::disk('local')->put($storedPath, $content);

        ProcessManifestImport::dispatch($storedPath, $userId, $fileName);

        Notification::make()
            ->title('Manifiesto en proceso ⏳')
            ->body('El manifiesto está siendo importado en segundo plano. Recibirás una notificación cuando termine.')
            ->info()
            ->send();

        return true;
    }
}
