<?php

namespace Tests\Feature\Edt;

use App\Models\Edt\EdtRoute;
use App\Models\Edt\EdtRouteAssignment;
use App\Models\Edt\EdtSeller;
use App\Models\Warehouse;
use App\Services\Edt\EdtRouteAssignmentService;
use App\Services\Edt\EdtRuleException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Historial de vendedores de una ruta: asignar desde una fecha, reasignar,
 * quitar y cancelar una programación, sin que dos vendedores tengan la
 * misma ruta el mismo día.
 */
class EdtRouteAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private EdtRouteAssignmentService $service;

    private Warehouse $oac;

    private EdtRoute $route;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05 09:00:00'); // lunes
        $this->service = app(EdtRouteAssignmentService::class);
        $this->oac = Warehouse::factory()->oac()->create();
        $this->route = EdtRoute::factory()->create(['warehouse_id' => $this->oac->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_asigna_la_ruta_desde_una_fecha(): void
    {
        $juan = $this->seller();

        $assignment = $this->service->assign($this->route, $juan, Carbon::parse('2026-10-05'));

        $this->assertSame('2026-10-05', $assignment->starts_on->toDateString());
        $this->assertNull($assignment->ends_on);
        $this->assertSame($this->oac->id, $assignment->warehouse_id);
        $this->assertSame($juan->id, $this->route->currentAssignment()->first()->seller_id);
    }

    public function test_reasignar_cierra_la_anterior_el_dia_antes(): void
    {
        $juan = $this->seller();
        $pedro = $this->seller();

        $first = $this->service->assign($this->route, $juan, Carbon::parse('2026-09-01'));
        $this->service->assign($this->route, $pedro, Carbon::parse('2026-10-05'));

        $this->assertSame('2026-10-04', $first->fresh()->ends_on->toDateString());
        $this->assertSame($pedro->id, $this->route->currentAssignment()->first()->seller_id);
        $this->assertSame(2, $this->route->assignments()->count());
    }

    public function test_se_puede_programar_a_futuro_y_el_actual_sigue_hasta_el_dia_antes(): void
    {
        $juan = $this->seller();
        $pedro = $this->seller();

        $this->service->assign($this->route, $juan, Carbon::parse('2026-09-01'));
        $scheduled = $this->service->assign($this->route, $pedro, Carbon::parse('2026-10-20'));

        // Hoy (5 de octubre) la ruta sigue siendo de Juan.
        $this->assertSame($juan->id, $this->route->currentAssignment()->first()->seller_id);
        $this->assertSame(EdtRouteAssignment::STATUS_SCHEDULED, $scheduled->statusOn());
        $this->assertSame($pedro->id, $this->route->assignments()->currentOn(Carbon::parse('2026-10-20'))->first()->seller_id);
    }

    public function test_no_acepta_una_fecha_igual_o_anterior_a_la_ultima_asignacion(): void
    {
        $this->service->assign($this->route, $this->seller(), Carbon::parse('2026-10-05'));

        $this->expectException(EdtRuleException::class);
        $this->expectExceptionMessage('empieza el 05/10/2026');

        $this->service->assign($this->route, $this->seller(), Carbon::parse('2026-10-05'));
    }

    public function test_no_reasigna_al_mismo_vendedor(): void
    {
        $juan = $this->seller();
        $this->service->assign($this->route, $juan, Carbon::parse('2026-09-01'));

        $this->expectException(EdtRuleException::class);
        $this->expectExceptionMessage('ya tiene esta ruta');

        $this->service->assign($this->route, $juan, Carbon::parse('2026-10-05'));
    }

    public function test_no_asigna_un_vendedor_de_otra_bodega_ni_inactivo(): void
    {
        $oas = Warehouse::factory()->oas()->create();

        try {
            $this->service->assign($this->route, $this->seller($oas), Carbon::parse('2026-10-05'));
            $this->fail('Asignó un vendedor de otra bodega.');
        } catch (EdtRuleException $e) {
            $this->assertStringContainsString('otra bodega', $e->getMessage());
        }

        $this->expectException(EdtRuleException::class);
        $this->expectExceptionMessage('inactivo');

        $this->service->assign($this->route, EdtSeller::factory()->inactive()->create(['warehouse_id' => $this->oac->id]), Carbon::parse('2026-10-05'));
    }

    public function test_la_bd_no_acepta_una_asignacion_entre_bodegas_distintas(): void
    {
        $other = $this->seller(Warehouse::factory()->oas()->create());

        $this->expectException(QueryException::class);

        // Saltándose el servicio: la FK compuesta lo frena.
        EdtRouteAssignment::query()->create([
            'warehouse_id' => $this->oac->id,
            'route_id' => $this->route->id,
            'seller_id' => $other->id,
            'starts_on' => '2026-10-05',
        ]);
    }

    public function test_la_bd_no_acepta_dos_asignaciones_abiertas_en_la_misma_ruta(): void
    {
        $this->service->assign($this->route, $this->seller(), Carbon::parse('2026-10-05'));

        $this->expectException(QueryException::class);

        DB::table('edt_route_assignments')->insert([
            'warehouse_id' => $this->oac->id,
            'route_id' => $this->route->id,
            'seller_id' => $this->seller()->id,
            'starts_on' => '2026-11-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_quitar_el_vendedor_cierra_la_asignacion(): void
    {
        $assignment = $this->service->assign($this->route, $this->seller(), Carbon::parse('2026-09-01'));

        $this->service->release($this->route, Carbon::parse('2026-10-05'));

        $this->assertSame('2026-10-05', $assignment->fresh()->ends_on->toDateString());
        // Hoy todavía la tiene (último día); mañana ya no.
        $this->assertNotNull($this->route->assignments()->currentOn(Carbon::parse('2026-10-05'))->first());
        $this->assertNull($this->route->assignments()->currentOn(Carbon::parse('2026-10-06'))->first());
    }

    public function test_no_quita_antes_de_que_empiece_ni_si_no_hay_vendedor(): void
    {
        try {
            $this->service->release($this->route, Carbon::parse('2026-10-05'));
            $this->fail('Quitó el vendedor de una ruta sin vendedor.');
        } catch (EdtRuleException $e) {
            $this->assertStringContainsString('no tiene vendedor', $e->getMessage());
        }

        $this->service->assign($this->route, $this->seller(), Carbon::parse('2026-10-10'));

        $this->expectException(EdtRuleException::class);
        $this->expectExceptionMessage('cancela la programación');

        $this->service->release($this->route, Carbon::parse('2026-10-05'));
    }

    public function test_cancelar_la_programacion_devuelve_la_ruta_al_vendedor_anterior(): void
    {
        $juan = $this->seller();
        $first = $this->service->assign($this->route, $juan, Carbon::parse('2026-09-01'));
        $this->service->assign($this->route, $this->seller(), Carbon::parse('2026-10-20'));

        $this->service->cancelScheduled($this->route);

        $this->assertSame(1, $this->route->assignments()->count());
        $this->assertNull($first->fresh()->ends_on);
        $this->assertSame($juan->id, $this->route->currentAssignment()->first()->seller_id);
    }

    public function test_no_cancela_una_asignacion_que_ya_empezo(): void
    {
        $this->service->assign($this->route, $this->seller(), Carbon::parse('2026-10-05'));

        $this->expectException(EdtRuleException::class);

        $this->service->cancelScheduled($this->route);
    }

    private function seller(?Warehouse $warehouse = null): EdtSeller
    {
        return EdtSeller::factory()->create(['warehouse_id' => ($warehouse ?? $this->oac)->id]);
    }
}
