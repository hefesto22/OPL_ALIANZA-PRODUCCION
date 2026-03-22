<?php

namespace App\Filament\Resources\Manifests\Schemas;

use App\Jobs\ProcessManifestImport;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class ManifestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Importar Manifiesto')
                    ->description('Arrastra el archivo JSON del manifiesto de Jaremar o haz clic para seleccionarlo.')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->schema([
                        FileUpload::make('json_file')
                            ->label('Archivo JSON del Manifiesto')
                            ->acceptedFileTypes(['application/json', 'text/plain'])
                            ->maxSize(102400)
                            ->required()
                            ->helperText('Solo archivos .json — máximo 100MB. Solo se procesarán facturas de bodegas OAC, OAO y OAS.'),
                    ]),
            ]);
    }

    public static function processUpload(
        string $filePath,
        int $userId,
        string $fileName = 'manifiesto.json'
    ): bool {
        $validator = resolve('App\Services\JsonValidatorService');
        $content   = file_get_contents($filePath);

        if ($content === false) {
            Notification::make()
                ->title('Error al leer el archivo')
                ->body('No se pudo leer el archivo subido.')
                ->danger()
                ->send();
            return false;
        }

        if (!$validator->validate($content)) {
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

        $storedPath = 'manifests/pending/' . basename($filePath);
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