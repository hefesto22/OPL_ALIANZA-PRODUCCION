<?php

namespace App\Jobs;

use App\Models\Manifest;
use App\Models\User;
use App\Services\ManifestImporterService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessManifestImport implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    /**
     * Cota máxima para el lock de unicidad (1h). Si el job queda zombie por
     * cualquier razón, después de 1h Horizon libera el lock y permite que
     * un nuevo intento se encole. 1h es generoso: el timeout del job es 30
     * min, así que con margen para retries internos.
     */
    public int $uniqueFor = 3600;

    protected const CHUNK_SIZE = 1000; // Subido de 500 → 1000

    /**
     * Cola `reports` — import pesado (miles de facturas en chunks),
     * perfilmente asimilable a los exports: lento, memoria alta,
     * debe NO bloquear la cola `high` donde corren las notificaciones
     * al usuario que acaba de subir el manifiesto.
     *
     * Nota: tries=3 acá OVERRIDE el tries=1 del supervisor-reports.
     * Es intencional: el importer es idempotente a nivel de chunk gracias
     * a INSERT ... ON CONFLICT DO UPDATE en invoices/invoice_lines, por lo
     * que un fallo transitorio de red o BD no obliga al usuario a re-subir.
     *
     * ShouldBeUnique + uniqueId() bloquean la corrida concurrente del mismo
     * archivo (escenario clásico: timeout intermedio → Horizon reintenta
     * sin saber si el primero todavía está vivo). El uniqueId combina el
     * userId con el hash del path para que dos usuarios diferentes puedan
     * subir archivos distintos en paralelo sin colisión.
     *
     * Se setea vía onQueue() en vez de `public $queue = 'reports'` porque
     * en PHP 8.3 + Laravel 11 el trait Queueable ya declara `public $queue`
     * sin valor, y declarar el mismo con valor inicial lanza Fatal error
     * por conflicto de traits.
     */
    public function __construct(
        protected string $storedPath,
        protected int $userId,
        protected string $originalFileName,
    ) {
        $this->onQueue('reports');
    }

    /**
     * Lock determinístico por (usuario, archivo).
     *
     * - md5 del path es suficiente: no es para seguridad, es solo un
     *   identificador estable y corto del archivo en el lock store.
     * - Incluir userId previene falsos negativos si dos usuarios
     *   coinciden en path tras Storage::disk reciclando nombres.
     */
    public function uniqueId(): string
    {
        return 'process-manifest-import:'.$this->userId.':'.md5($this->storedPath);
    }

    public function handle(ManifestImporterService $importer): void
    {
        try {
            $fullPath = Storage::disk('local')->path($this->storedPath);
            $content = file_get_contents($fullPath);
            $data = json_decode($content, true);

            if (! is_array($data)) {
                throw new \Exception('El archivo JSON no tiene un formato válido.');
            }

            $total = count($data);
            $chunks = collect($data)->chunk(self::CHUNK_SIZE);

            Log::info("Iniciando importación: {$total} facturas en {$chunks->count()} chunks.", [
                'file' => $this->originalFileName,
                'user_id' => $this->userId,
            ]);

            $manifest = $importer->createManifest($data, $this->userId);

            $chunks->each(function ($chunk, $index) use ($importer, $manifest, $chunks) {
                $importer->importChunk($manifest, $chunk->values()->all());

                Log::info('Chunk '.($index + 1)."/{$chunks->count()} procesado.", [
                    'manifest_id' => $manifest->id,
                    'facturas' => $chunk->count(),
                ]);
            });

            $manifest->recalculateTotals();

            Storage::disk('local')->delete($this->storedPath);

            $this->notifySuccess($manifest, $importer->getWarnings());

            if ($importer->hasUnknownWarehouses()) {
                $this->notifyUnknownWarehouses($manifest, $importer->getUnknownWarehouses());
            }

        } catch (\Exception $e) {
            Log::error('Error importando manifiesto.', [
                'user_id' => $this->userId,
                'file' => $this->originalFileName,
                'error' => $e->getMessage(),
            ]);

            $this->notifyError($e->getMessage());
            $this->fail($e);
        }
    }

    protected function notifySuccess(Manifest $manifest, array $warnings): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $body = "Manifiesto #{$manifest->number} importado correctamente. ".
                "{$manifest->invoices_count} facturas por L. ".
                number_format($manifest->total_invoices, 2);

        if (! empty($warnings)) {
            $body .= ' | ⚠ '.implode(' | ', $warnings);
        }

        Notification::make()
            ->title('Manifiesto importado ✅')
            ->body($body)
            ->success()
            ->actions([
                Action::make('ver')
                    ->label('Ver Manifiesto')
                    ->url(route('filament.admin.resources.manifests.view', $manifest))
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }

    protected function notifyUnknownWarehouses(Manifest $manifest, array $unknownCodes): void
    {
        $admins = User::role(['super_admin', 'admin'])->get();
        $codes = implode(', ', $unknownCodes);

        foreach ($admins as $admin) {
            Notification::make()
                ->title('⚠ Bodegas desconocidas en manifiesto #'.$manifest->number)
                ->body("Se encontraron facturas de bodegas no registradas: {$codes}. Estas facturas están en estado 'pendiente'.")
                ->warning()
                ->actions([
                    Action::make('ver')
                        ->label('Ver Manifiesto')
                        ->url(route('filament.admin.resources.manifests.view', $manifest))
                        ->markAsRead(),
                ])
                ->sendToDatabase($admin);
        }
    }

    protected function notifyError(string $message): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        Notification::make()
            ->title('Error al importar manifiesto ❌')
            ->body("Archivo: {$this->originalFileName}. Error: {$message}")
            ->danger()
            ->sendToDatabase($user);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Job ProcessManifestImport falló definitivamente.', [
            'user_id' => $this->userId,
            'file' => $this->originalFileName,
            'error' => $exception->getMessage(),
        ]);

        $this->notifyError('El proceso falló después de varios intentos. Contacte al administrador.');
    }
}
