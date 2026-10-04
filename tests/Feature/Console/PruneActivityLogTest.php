<?php

namespace Tests\Feature\Console;

use App\Console\Commands\PruneActivityLog;
use App\Support\Edt\EdtModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * activitylog:prune — retención de 90 días con bitácoras permanentes.
 *
 * Lo que protege:
 *  - La bitácora del EDT ('edt') no se borra nunca: es el historial de
 *    cambios del catálogo y se consulta meses después.
 *  - Todo lo demás (Jaremar, api, default y filas sin log_name) se sigue
 *    borrando igual que antes del EDT.
 */
class PruneActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_bitacora_del_edt_es_permanente(): void
    {
        $this->assertContains(EdtModule::LOG_NAME, PruneActivityLog::PERMANENT_LOG_NAMES);
    }

    public function test_borra_lo_viejo_y_conserva_lo_del_edt_y_lo_reciente(): void
    {
        $viejo = now()->subDays(120);
        $reciente = now()->subDays(10);

        $edtViejo = $this->activity(EdtModule::LOG_NAME, $viejo);
        $defaultViejo = $this->activity('default', $viejo);
        $apiViejo = $this->activity('api', $viejo);
        $sinNombreViejo = $this->activity(null, $viejo);
        $defaultReciente = $this->activity('default', $reciente);
        $edtReciente = $this->activity(EdtModule::LOG_NAME, $reciente);

        $this->artisan('activitylog:prune', ['--days' => 90])->assertSuccessful();

        // Se conservan: todo lo del EDT y todo lo reciente.
        foreach ([$edtViejo, $edtReciente, $defaultReciente] as $id) {
            $this->assertDatabaseHas('activity_log', ['id' => $id]);
        }

        // Se borran: lo viejo que no es del EDT, incluidas las filas sin log_name.
        foreach ([$defaultViejo, $apiViejo, $sinNombreViejo] as $id) {
            $this->assertDatabaseMissing('activity_log', ['id' => $id]);
        }
    }

    public function test_borra_en_lotes_sin_tocar_el_edt(): void
    {
        // Más de un lote (1000) de filas viejas para cubrir el do/while.
        $viejo = now()->subDays(200)->toDateTimeString();
        $rows = [];
        for ($i = 0; $i < 1205; $i++) {
            $rows[] = $this->row('default', $viejo);
        }
        DB::table('activity_log')->insert($rows);
        $edt = $this->activity(EdtModule::LOG_NAME, now()->subDays(200));

        $this->artisan('activitylog:prune', ['--days' => 90])->assertSuccessful();

        $this->assertSame(0, DB::table('activity_log')->where('log_name', 'default')->count());
        $this->assertDatabaseHas('activity_log', ['id' => $edt]);
    }

    private function activity(?string $logName, \DateTimeInterface $at): int
    {
        return DB::table('activity_log')->insertGetId($this->row($logName, $at->format('Y-m-d H:i:s')));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?string $logName, string $at): array
    {
        return [
            'log_name' => $logName,
            'description' => 'prueba',
            'properties' => '{}',
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }
}
