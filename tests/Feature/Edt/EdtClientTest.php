<?php

namespace Tests\Feature\Edt;

use App\Models\Edt\EdtClient;
use App\Models\Geo\Department;
use App\Models\Geo\Municipality;
use App\Models\Warehouse;
use App\Services\Edt\EdtClientCodeGenerator;
use App\Support\Edt\EdtModule;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Modelo EdtClient: código automático o manual, mayúsculas, crédito
 * opcional, zona consistente y bitácora sin RTN completo.
 */
class EdtClientTest extends TestCase
{
    use RefreshDatabase;

    // ── Código ──────────────────────────────────────────────────────

    public function test_sin_codigo_se_asigna_el_siguiente_automatico(): void
    {
        // La secuencia de Postgres no se reinicia entre tests: se compara
        // contra el número que dio el primero, no contra C-000001.
        $first = EdtClient::factory()->create();
        $second = EdtClient::factory()->create(['code' => '   ']);

        $this->assertMatchesRegularExpression('/^C-\d{6}$/', $first->code);
        $this->assertSame(
            EdtClientCodeGenerator::format($this->number($first->code) + 1),
            $second->code,
        );
    }

    public function test_un_codigo_escrito_se_respeta_en_mayusculas(): void
    {
        $client = EdtClient::factory()->create(['code' => '  cli-0045 ']);

        $this->assertSame('CLI-0045', $client->fresh()->code);
    }

    public function test_el_automatico_salta_un_codigo_que_ya_se_escribio_a_mano(): void
    {
        $first = EdtClient::factory()->create();
        $next = EdtClientCodeGenerator::format($this->number($first->code) + 1);

        // Alguien escribió a mano justo el que tocaba.
        EdtClient::factory()->create(['code' => $next]);

        $auto = EdtClient::factory()->create();

        $this->assertSame(EdtClientCodeGenerator::format($this->number($first->code) + 2), $auto->code);
    }

    public function test_el_codigo_es_unico(): void
    {
        EdtClient::factory()->create(['code' => 'ABC']);

        $this->expectException(UniqueConstraintViolationException::class);

        EdtClient::factory()->create(['code' => 'abc']);
    }

    // ── Datos ───────────────────────────────────────────────────────

    public function test_todo_el_texto_se_guarda_en_mayusculas(): void
    {
        $client = EdtClient::factory()->create([
            'name' => '  josé   peña ',
            'business_name' => 'pulpería la bendición',
            'business_type' => 'minisúper',
            'neighborhood' => 'barrio el centro',
            'address' => '2 cuadras al sur de la iglesia',
        ])->fresh();

        $this->assertSame('JOSÉ PEÑA', $client->name);
        $this->assertSame('PULPERÍA LA BENDICIÓN', $client->business_name);
        $this->assertSame('MINISÚPER', $client->business_type);
        $this->assertSame('BARRIO EL CENTRO', $client->neighborhood);
        $this->assertSame('2 CUADRAS AL SUR DE LA IGLESIA', $client->address);
    }

    public function test_el_rtn_se_guarda_solo_con_digitos_y_la_bitacora_lo_enmascara(): void
    {
        $client = EdtClient::factory()->create(['rtn' => '0401-1990-123456']);

        $this->assertSame('04011990123456', $client->fresh()->rtn);

        $log = Activity::query()->where('subject_type', EdtClient::class)->where('subject_id', $client->id)->sole();

        $this->assertSame(EdtModule::LOG_NAME, $log->log_name);
        $this->assertSame('**********3456', $log->properties['attributes']['rtn']);
        $this->assertStringNotContainsString('04011990123456', json_encode($log->properties));
    }

    public function test_el_rtn_debe_tener_14_digitos(): void
    {
        $client = EdtClient::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('edt_clients')->where('id', $client->id)->update(['rtn' => '123']);
    }

    // ── Zona ────────────────────────────────────────────────────────

    public function test_la_zona_queda_con_su_municipio_y_departamento(): void
    {
        $client = EdtClient::factory()->inMunicipality('0401')->create();

        $this->assertSame('SANTA ROSA DE COPÁN', $client->municipality->name);
        $this->assertSame('COPÁN', $client->department->name);
    }

    public function test_la_bd_no_acepta_un_municipio_de_otro_departamento(): void
    {
        $sinuapa = Municipality::query()->where('code', '1416')->sole(); // Ocotepeque
        $copan = Department::query()->where('code', '04')->sole();

        $this->expectException(QueryException::class);

        EdtClient::factory()->create([
            'municipality_id' => $sinuapa->id,
            'department_id' => $copan->id,
        ]);
    }

    public function test_no_se_puede_borrar_una_bodega_que_tiene_clientes(): void
    {
        $warehouse = Warehouse::factory()->oac()->create();
        EdtClient::factory()->create(['warehouse_id' => $warehouse->id]);

        $this->expectException(QueryException::class);

        $warehouse->forceDelete();
    }

    // ── Crédito ─────────────────────────────────────────────────────

    public function test_el_credito_puede_quedar_sin_limite_ni_dias(): void
    {
        $client = EdtClient::factory()->withCredit(limit: null, days: null)->create()->fresh();

        $this->assertTrue($client->credit_enabled);
        $this->assertNull($client->credit_limit);
        $this->assertNull($client->credit_days);
    }

    public function test_quitar_el_credito_limpia_limite_y_dias(): void
    {
        $client = EdtClient::factory()->withCredit('15000.00', 30)->create();

        $client->update(['credit_enabled' => false]);

        $client->refresh();
        $this->assertFalse($client->credit_enabled);
        $this->assertNull($client->credit_limit);
        $this->assertNull($client->credit_days);
    }

    public function test_sin_credito_no_se_guarda_un_limite_aunque_venga(): void
    {
        $client = EdtClient::factory()->create([
            'credit_enabled' => false,
            'credit_limit' => '9000',
            'credit_days' => 15,
        ])->fresh();

        $this->assertNull($client->credit_limit);
        $this->assertNull($client->credit_days);
    }

    public function test_la_bd_exige_limite_positivo_y_dias_entre_1_y_365(): void
    {
        $client = EdtClient::factory()->withCredit()->create();

        foreach ([['credit_limit' => 0], ['credit_days' => 0], ['credit_days' => 366]] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('edt_clients')->where('id', $client->id)->update($invalid));
                $this->fail('La BD aceptó '.json_encode($invalid));
            } catch (QueryException $e) {
                $this->assertStringContainsString('edt_clients_credit', $e->getMessage());
            }
        }
    }

    public function test_la_bd_no_acepta_limite_si_el_cliente_no_tiene_credito(): void
    {
        $client = EdtClient::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('edt_clients_credit_terms_need_credit');

        DB::table('edt_clients')->where('id', $client->id)->update(['credit_limit' => 500]);
    }

    private function number(string $code): int
    {
        return (int) substr($code, strlen(EdtClientCodeGenerator::PREFIX));
    }
}
