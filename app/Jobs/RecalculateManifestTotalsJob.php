<?php

namespace App\Jobs;

use App\Models\Manifest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Recalcula los totales de un manifiesto en background.
 *
 * ¿Por qué un Job y no sincrónico?
 *   - recalculateTotals() lanza 6+ queries sobre tablas grandes.
 *   - Correrlo dentro de la transacción de createReturn/approveReturn
 *     añadía latencia innecesaria al request del usuario.
 *   - La queue suele procesar en < 1 segundo, por lo que el usuario
 *     ve los totales actualizados al navegar de vuelta al manifiesto.
 *
 * Idempotencia:
 *   - Si dos returns se crean casi simultáneamente para el mismo manifiesto,
 *     ambos jobs se encolan. Cada job leerá los datos más frescos al ejecutar,
 *     y el último en terminar tendrá los totales correctos. No hay riesgo de
 *     inconsistencia porque el cálculo siempre lee de la BD en ese momento.
 */
class RecalculateManifestTotalsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        protected int $manifestId,
    ) {}

    public function handle(): void
    {
        $manifest = Manifest::find($this->manifestId);

        if (! $manifest) {
            Log::warning('RecalculateManifestTotalsJob: manifiesto no encontrado.', [
                'manifest_id' => $this->manifestId,
            ]);

            return;
        }

        $manifest->recalculateTotals();

        Log::info("Totales del manifiesto #{$manifest->number} recalculados en background.", [
            'manifest_id' => $manifest->id,
            'total_invoices' => $manifest->total_invoices,
            'total_returns' => $manifest->total_returns,
            'total_to_deposit' => $manifest->total_to_deposit,
            'difference' => $manifest->difference,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RecalculateManifestTotalsJob falló definitivamente.', [
            'manifest_id' => $this->manifestId,
            'error' => $exception->getMessage(),
        ]);
    }
}
