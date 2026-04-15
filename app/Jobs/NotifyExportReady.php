<?php

namespace App\Jobs;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Notifica al usuario que una exportación Excel está lista para descargar.
 *
 * Se encadena después de un Excel::queue() o Excel::store() con ShouldQueue.
 * Envía una notificación de Filament (database) con un botón de descarga.
 *
 * El archivo se almacena en storage/app/exports/ y se auto-elimina
 * después de 24 horas vía el scheduler (o manualmente).
 */
class NotifyExportReady implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $userId,
        protected string $filePath,
        protected string $fileName,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        // Verificar que el archivo realmente se generó
        if (! Storage::disk('local')->exists($this->filePath)) {
            Log::warning('NotifyExportReady: archivo no encontrado', [
                'user_id' => $this->userId,
                'file_path' => $this->filePath,
            ]);

            Notification::make()
                ->title('Error en exportación')
                ->body("No se pudo generar el archivo {$this->fileName}. Intenta nuevamente.")
                ->danger()
                ->sendToDatabase($user);

            return;
        }

        Notification::make()
            ->title('Exportación lista')
            ->body("Tu archivo {$this->fileName} está listo para descargar.")
            ->success()
            ->actions([
                Action::make('download')
                    ->label('Descargar')
                    ->url(route('exports.download', ['file' => $this->filePath]))
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }
}
