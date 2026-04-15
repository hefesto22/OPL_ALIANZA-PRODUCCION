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

    /**
     * Cola por defecto: `high`.
     *
     * Este job es liviano (una notificación a DB) pero *bloquea* la UX:
     * el usuario está esperando ver "Exportación lista". Si cae en la
     * misma cola que los exports pesados, una notificación de 50ms
     * puede quedar esperando 10 minutos detrás de un export.
     *
     * Siempre se dispara encadenado con ->onQueue('high') desde los
     * call sites de Filament, pero fijarlo acá también defiende contra
     * olvidos en chains futuros.
     *
     * Nota: se setea vía onQueue() en vez de `public $queue = 'high'` porque
     * en PHP 8.3 + Laravel 11 el trait Queueable ya declara `public $queue`
     * sin valor, y declarar el mismo con valor inicial lanza Fatal error
     * por conflicto de traits.
     */
    public function __construct(
        protected int $userId,
        protected string $filePath,
        protected string $fileName,
    ) {
        $this->onQueue('high');
    }

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
