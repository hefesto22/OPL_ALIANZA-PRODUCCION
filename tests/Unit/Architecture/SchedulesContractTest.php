<?php

namespace Tests\Unit\Architecture;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Test arquitectural — contrato de schedules.
 *
 * ¿Qué protege este test?
 *
 * En un setup multi-server (más de un nodo PHP-FPM corriendo el mismo
 * código contra el mismo Postgres + Redis), CADA nodo ejecuta su propio
 * scheduler. Sin `->onOneServer()`, los schedules corren N veces en
 * paralelo:
 *   - `activitylog:prune` borraría las mismas filas N veces (race con error).
 *   - `limpiar-exports-huerfanos` borraría el mismo archivo N veces.
 *   - `limpiar-comprobantes-deposito` chunkeaba la misma tabla N veces.
 *
 * `onOneServer()` adquiere un lock de cache (Redis) basado en el `->name()`
 * del schedule, garantizando que solo un nodo ejecute cada tick. Por eso
 * `name()` también es obligatorio (sin name, onOneServer no puede generar
 * la clave del lock).
 *
 * Si un schedule futuro se agrega sin `onOneServer()`, este test rojo lo
 * caza antes del merge.
 */
class SchedulesContractTest extends TestCase
{
    /**
     * Schedules que, por diseño, NO deben usar onOneServer().
     * Hoy ninguno. Si alguna vez hay un schedule que DEBE correr en cada
     * nodo (raro — quizás un health check local), agregarlo acá con
     * justificación documentada.
     */
    private const SCHEDULES_EXCEPTIONS = [];

    public function test_every_scheduled_task_runs_on_one_server(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $events = $schedule->events();

        $this->assertNotEmpty(
            $events,
            'No se descubrió ningún schedule. ¿Está routes/console.php cargado?'
        );

        foreach ($events as $event) {
            $name = $event->description ?? '(sin nombre)';

            if (in_array($name, self::SCHEDULES_EXCEPTIONS, true)) {
                continue;
            }

            $this->assertNotEmpty(
                $event->description,
                'Hay un schedule sin `->name(...)` declarado. Sin name, '.
                'onOneServer no puede generar la clave del lock. '.
                'Comando/closure: '.(method_exists($event, 'getSummaryForDisplay')
                    ? $event->getSummaryForDisplay()
                    : 'desconocido')
            );

            $this->assertTrue(
                $event->onOneServer,
                "Schedule '{$name}' debe llamar `->onOneServer()`. ".
                'En multi-server cada nodo ejecutaría el schedule en paralelo, '.
                'causando race conditions o duplicación de efectos.'
            );
        }
    }
}
