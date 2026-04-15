<?php

namespace Tests\Feature\Jobs;

use App\Jobs\NotifyExportReady;
use App\Jobs\ProcessManifestImport;
use App\Jobs\RecalculateManifestTotalsJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests de routing de jobs a la cola correcta de Horizon.
 *
 * Estos tests prueban COMPORTAMIENTO (qué cola recibe el job cuando se
 * despacha), no la propiedad interna `$queue`. La diferencia importa:
 *
 *   - Test de propiedad: `$job->queue === 'high'` — si alguien refactoriza
 *     y la propiedad pasa a llamarse `$targetQueue`, el test falla aunque
 *     el comportamiento sea correcto.
 *
 *   - Test de comportamiento (este): `Queue::fake(); dispatch; assertPushedOn('high')` —
 *     sobrevive a cualquier refactor interno mientras la cola siga siendo la correcta.
 *
 * Cubre los 3 jobs del sistema. Si alguien agrega un nuevo Job, el test
 * `QueueContractTest` lo valida estructuralmente; este valida su runtime.
 */
class JobQueueRoutingTest extends TestCase
{
    public function test_notify_export_ready_dispatches_on_high_queue(): void
    {
        Queue::fake();

        NotifyExportReady::dispatch(1, 'exports/sample.xlsx', 'sample.xlsx');

        Queue::assertPushedOn('high', NotifyExportReady::class);
    }

    public function test_recalculate_manifest_totals_dispatches_on_high_queue(): void
    {
        Queue::fake();

        RecalculateManifestTotalsJob::dispatch(1);

        Queue::assertPushedOn('high', RecalculateManifestTotalsJob::class);
    }

    public function test_process_manifest_import_dispatches_on_reports_queue(): void
    {
        Queue::fake();

        ProcessManifestImport::dispatch('uploads/manifest.json', 1, 'manifest.json');

        Queue::assertPushedOn('reports', ProcessManifestImport::class);
    }

    /**
     * Test de defensa: si alguien override manualmente la cola al despachar,
     * el override debe ganar. Esto es estándar de Laravel pero vale confirmarlo
     * porque nuestro flujo de export chain USA ese override (->onQueue('high')).
     */
    public function test_on_queue_override_wins_over_class_default(): void
    {
        Queue::fake();

        // NotifyExportReady default es 'high'. Override a 'default' para probar.
        NotifyExportReady::dispatch(1, 'exports/x.xlsx', 'x.xlsx')->onQueue('default');

        Queue::assertPushedOn('default', NotifyExportReady::class);
    }
}
