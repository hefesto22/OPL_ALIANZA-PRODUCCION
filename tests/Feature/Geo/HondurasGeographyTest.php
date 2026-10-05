<?php

namespace Tests\Feature\Geo;

use App\Models\Geo\Department;
use App\Models\Geo\Municipality;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Catálogo oficial de departamentos y municipios (lo crea la migración).
 *
 * Si alguien edita la lista y se come un municipio, o mete uno en el
 * departamento equivocado, las zonas de los vendedores quedarían mal.
 */
class HondurasGeographyTest extends TestCase
{
    use RefreshDatabase;

    public function test_hay_18_departamentos_y_298_municipios(): void
    {
        $this->assertSame(18, Department::query()->count());
        $this->assertSame(298, Municipality::query()->count());
    }

    public function test_cada_departamento_tiene_sus_municipios_oficiales(): void
    {
        $expected = [
            'ATLÁNTIDA' => 8, 'COLÓN' => 10, 'COMAYAGUA' => 21, 'COPÁN' => 23, 'CORTÉS' => 12,
            'CHOLUTECA' => 16, 'EL PARAÍSO' => 19, 'FRANCISCO MORAZÁN' => 28, 'GRACIAS A DIOS' => 6,
            'INTIBUCÁ' => 17, 'ISLAS DE LA BAHÍA' => 4, 'LA PAZ' => 19, 'LEMPIRA' => 28,
            'OCOTEPEQUE' => 16, 'OLANCHO' => 23, 'SANTA BÁRBARA' => 28, 'VALLE' => 9, 'YORO' => 11,
        ];

        $actual = Department::query()->withCount('municipalities')->pluck('municipalities_count', 'name')->all();

        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    public function test_el_codigo_del_municipio_empieza_con_el_de_su_departamento(): void
    {
        $wrong = Municipality::query()
            ->join('hn_departments', 'hn_departments.id', '=', 'hn_municipalities.department_id')
            ->whereRaw('left(hn_municipalities.code, 2) <> hn_departments.code')
            ->count();

        $this->assertSame(0, $wrong);
    }

    public function test_las_cabeceras_de_las_bodegas_estan_en_su_departamento(): void
    {
        // Las 4 bodegas: OAC Copán, OAS Santa Bárbara, OAO Ocotepeque, OAI Intibucá.
        $cases = [
            '0401' => ['SANTA ROSA DE COPÁN', 'COPÁN'],
            '1601' => ['SANTA BÁRBARA', 'SANTA BÁRBARA'],
            '1401' => ['NUEVA OCOTEPEQUE', 'OCOTEPEQUE'],
            '1001' => ['LA ESPERANZA', 'INTIBUCÁ'],
        ];

        foreach ($cases as $code => [$municipality, $department]) {
            $row = Municipality::query()->with('department')->where('code', $code)->sole();

            $this->assertSame($municipality, $row->name);
            $this->assertSame($department, $row->department->name);
        }
    }

    public function test_todo_esta_en_mayusculas(): void
    {
        $names = Department::query()->pluck('name')->merge(Municipality::query()->pluck('name'));

        foreach ($names as $name) {
            $this->assertSame(mb_strtoupper($name), $name);
        }
    }

    public function test_un_departamento_no_repite_nombre_de_municipio(): void
    {
        $copan = Department::query()->where('code', '04')->sole();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('hn_municipalities')->insert([
            'department_id' => $copan->id,
            'code' => '0499',
            'name' => 'SANTA ROSA DE COPÁN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_options_for_devuelve_solo_los_municipios_del_departamento(): void
    {
        $ocotepeque = Department::query()->where('code', '14')->sole();

        $options = Municipality::optionsFor($ocotepeque->id);

        $this->assertCount(16, $options);
        $this->assertContains('SINUAPA', $options);
        $this->assertNotContains('SANTA ROSA DE COPÁN', $options);
        $this->assertSame([], Municipality::optionsFor(null));
    }
}
