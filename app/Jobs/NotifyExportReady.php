<?php

namespace App\Jobs;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

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
     * Número máximo de intentos. Tres es el balance entre cubrir fallos
     * transitorios (Redis intermitente, lock momentáneo en notifications
     * table) y no spammear logs con un job condenado.
     */
    public int $tries = 3;

    /**
     * Timeout en segundos. El job es ligero — un find + una notificación
     * a DB. 60s es generoso pero deja margen si Postgres tiene contención.
     */
    public int $timeout = 60;

    /**
     * Backoff exponencial entre reintentos. 10s para reintentar fallos
     * transitorios casi-inmediatos, 30s y 60s para fallos de infraestructura
     * que pueden tardar más en resolverse.
     *
     * @return int[]
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

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
                    // Signed URL con TTL 24h — alineado con la limpieza
                    // automática del archivo (storage/app/exports/ se
                    // recicla a las 24h). Si el archivo desaparece el
                    // controller responde 404; si el link expira, 403.
                    ->url(URL::temporarySignedRoute(
                        'exports.download',
                        now()->addHours(24),
                        ['file' => $this->filePath]
                    ))
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }

    /**
     * Handler de fallo definitivo (tras agotar tries). Loguea con contexto
     * para diagnóstico. NO intenta notificar al usuario porque si llegamos
     * acá es probable que el sistema de notificaciones sea justo lo que falló.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('NotifyExportReady falló definitivamente.', [
            'user_id' => $this->userId,
            'file_path' => $this->filePath,
            'file_name' => $this->fileName,
            'error' => $exception->getMessage(),
        ]);
    }
}
