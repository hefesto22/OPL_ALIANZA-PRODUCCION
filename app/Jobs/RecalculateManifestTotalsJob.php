<?php

namespace App\Jobs;

use App\Models\Manifest;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
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
 * Idempotencia bajo encolado paralelo (ShouldBeUniqueUntilProcessing):
 *   - Si llegan N devoluciones rápidas al mismo manifiesto, sin uniqueness
 *     se encolarían N jobs IDÉNTICOS, todos haciendo el mismo trabajo.
 *   - ShouldBeUniqueUntilProcessing libera el lock al EMPEZAR a procesarse
 *     (no al terminar). Variante elegida sobre ShouldBeUnique clásico
 *     porque permite que un cambio que llegue DURANTE la ejecución del
 *     primer job se encole y procese a continuación — preservando la
 *     garantía existente de que "el último cálculo siempre gana".
 *   - El uniqueId se basa en manifest_id: jobs para manifiestos distintos
 *     corren en paralelo sin contención.
 */
class RecalculateManifestTotalsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Cola `high` — el usuario acaba de registrar/aprobar/cancelar una
     * devolución y espera ver los totales actualizados al navegar de vuelta
     * al manifiesto. Ponerlo detrás de exports pesados rompe esa UX.
     *
     * El job es ligero (6 queries agregadas + 1 update) y el timeout de
     * 60s del supervisor-high cubre con margen su perfil real (< 1s).
     *
     * Nota: se setea vía onQueue() en vez de `public $queue = 'high'` porque
     * en PHP 8.3 + Laravel 11 el trait Queueable ya declara `public $queue`
     * sin valor, y declarar el mismo con valor inicial lanza Fatal error
     * por conflicto de traits.
     */
    public function __construct(
        protected int $manifestId,
    ) {
        $this->onQueue('high');
    }

    /**
     * ID único para deduplicar jobs pendientes. Mientras este job está
     * encolado (no comenzó a procesarse aún), cualquier otro dispatch con
     * el mismo manifest_id es descartado por Laravel. Al iniciar el handle,
     * el lock se libera (ShouldBeUniqueUntilProcessing) y nuevos jobs
     * pueden encolarse — garantía: el último siempre gana.
     */
    public function uniqueId(): string
    {
        return 'recalc-manifest:'.$this->manifestId;
    }

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
