<?php

namespace App\Jobs;

use App\Models\Manifest;
use App\Models\User;
use App\Services\ManifestImporterService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessManifestImport implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 1800;

    protected const CHUNK_SIZE = 1000; // Subido de 500 → 1000

    public function __construct(
        protected string $storedPath,
        protected int    $userId,
        protected string $originalFileName,
    ) {}

    public function handle(ManifestImporterService $importer): void
    {
        try {
            $fullPath = Storage::disk('local')->path($this->storedPath);
            $content  = file_get_contents($fullPath);
            $data     = json_decode($content, true);

            if (!is_array($data)) {
                throw new \Exception('El archivo JSON no tiene un formato válido.');
            }

            $total  = count($data);
            $chunks = collect($data)->chunk(self::CHUNK_SIZE);

            Log::info("Iniciando importación: {$total} facturas en {$chunks->count()} chunks.", [
                'file'    => $this->originalFileName,
                'user_id' => $this->userId,
            ]);

            $manifest = $importer->createManifest($data, $this->userId);

            $chunks->each(function ($chunk, $index) use ($importer, $manifest, $chunks) {
                $importer->importChunk($manifest, $chunk->values()->all());

                Log::info("Chunk " . ($index + 1) . "/{$chunks->count()} procesado.", [
                    'manifest_id' => $manifest->id,
                    'facturas'    => $chunk->count(),
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
                'file'    => $this->originalFileName,
                'error'   => $e->getMessage(),
            ]);

            $this->notifyError($e->getMessage());
            $this->fail($e);
        }
    }

    protected function notifySuccess(Manifest $manifest, array $warnings): void
    {
        $user = User::find($this->userId);
        if (!$user) return;

        $body = "Manifiesto #{$manifest->number} importado correctamente. " .
                "{$manifest->invoices_count} facturas por L. " .
                number_format($manifest->total_invoices, 2);

        if (!empty($warnings)) {
            $body .= ' | ⚠ ' . implode(' | ', $warnings);
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
        $codes  = implode(', ', $unknownCodes);

        foreach ($admins as $admin) {
            Notification::make()
                ->title('⚠ Bodegas desconocidas en manifiesto #' . $manifest->number)
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
        if (!$user) return;

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
            'file'    => $this->originalFileName,
            'error'   => $exception->getMessage(),
        ]);

        $this->notifyError('El proceso falló después de varios intentos. Contacte al administrador.');
    }
}