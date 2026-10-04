<?php

namespace Tests\Feature\Edt;

use App\Models\Edt\EdtSupplier;
use App\Support\Edt\EdtModule;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Modelo EdtSupplier: normalización de datos, invariantes de la tabla
 * (CHECK / unique en Postgres) y bitácora del EDT.
 *
 * Los CHECK son la última línea de defensa: el formulario valida lo mismo,
 * pero un seeder, un import o tinker no pasan por el formulario.
 */
class EdtSupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_crea_un_proveedor_valido_con_15_por_ciento(): void
    {
        $supplier = EdtSupplier::factory()->create();

        $this->assertTrue($supplier->is_active);
        $this->assertSame('15.00', $supplier->fresh()->operation_discount_pct);
        $this->assertDatabaseHas('edt_suppliers', ['id' => $supplier->id]);
    }

    public function test_el_descuento_por_defecto_de_la_tabla_es_15(): void
    {
        // Sin pasar el descuento: lo pone el DEFAULT de la columna.
        DB::table('edt_suppliers')->insert([
            'code' => 'DEF',
            'name' => 'Proveedor sin descuento explícito',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('15.00', EdtSupplier::where('code', 'DEF')->value('operation_discount_pct'));
    }

    public function test_el_codigo_se_guarda_en_mayusculas_y_sin_espacios(): void
    {
        $supplier = EdtSupplier::factory()->create(['code' => '  cbc ']);

        $this->assertSame('CBC', $supplier->fresh()->code);
    }

    public function test_todo_el_texto_se_guarda_en_mayusculas(): void
    {
        // Regla del EDT: todo el texto sale en mayúsculas, con tildes y eñes.
        $supplier = EdtSupplier::factory()->create([
            'name' => '  Alimentos   Gran Día ',
            'contact_name' => 'josé peña',
            'address' => 'Barrio el Centro, Santa Rosa de Copán',
        ])->fresh();

        $this->assertSame('ALIMENTOS GRAN DÍA', $supplier->name);
        $this->assertSame('JOSÉ PEÑA', $supplier->contact_name);
        $this->assertSame('BARRIO EL CENTRO, SANTA ROSA DE COPÁN', $supplier->address);
    }

    public function test_el_correo_se_guarda_en_minusculas(): void
    {
        $supplier = EdtSupplier::factory()->create(['email' => ' Ventas@CBC.com.hn '])->fresh();

        $this->assertSame('ventas@cbc.com.hn', $supplier->email);
    }

    public function test_el_rtn_se_guarda_solo_con_digitos(): void
    {
        $supplier = EdtSupplier::factory()->create(['rtn' => '0501-1990-123456']);

        $this->assertSame('05011990123456', $supplier->fresh()->rtn);
    }

    public function test_el_rtn_vacio_se_guarda_como_null(): void
    {
        $supplier = EdtSupplier::factory()->create(['rtn' => '']);

        $this->assertNull($supplier->fresh()->rtn);
    }

    public function test_la_base_de_datos_rechaza_un_codigo_repetido(): void
    {
        EdtSupplier::factory()->create(['code' => 'CBC']);

        $this->expectException(UniqueConstraintViolationException::class);

        // Distinto en minúsculas, igual después de normalizar.
        EdtSupplier::factory()->create(['code' => 'cbc']);
    }

    public function test_la_base_de_datos_rechaza_un_rtn_que_no_tiene_14_digitos(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('edt_suppliers_rtn_format');

        EdtSupplier::factory()->create(['rtn' => '0501199012345']); // 13 dígitos
    }

    public function test_la_base_de_datos_rechaza_un_descuento_de_100_o_mas(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('edt_suppliers_operation_discount_range');

        EdtSupplier::factory()->create(['operation_discount_pct' => 100]);
    }

    public function test_la_base_de_datos_rechaza_un_descuento_negativo(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('edt_suppliers_operation_discount_range');

        EdtSupplier::factory()->create(['operation_discount_pct' => -1]);
    }

    public function test_crear_un_proveedor_queda_en_la_bitacora_del_edt(): void
    {
        $supplier = EdtSupplier::factory()->create(['code' => 'CBC']);

        $activity = $this->lastActivityFor($supplier);

        $this->assertSame(EdtModule::LOG_NAME, $activity->log_name);
        $this->assertSame('created', $activity->event);
        $this->assertSame('CBC', $activity->properties['attributes']['code']);
    }

    public function test_cambiar_el_descuento_guarda_el_valor_anterior_y_el_nuevo(): void
    {
        $supplier = EdtSupplier::factory()->create();

        $supplier->update(['operation_discount_pct' => 12.5]);

        $activity = $this->lastActivityFor($supplier);

        $this->assertSame('updated', $activity->event);
        $this->assertSame('15.00', $activity->properties['old']['operation_discount_pct']);
        $this->assertSame('12.50', $activity->properties['attributes']['operation_discount_pct']);
        // logOnlyDirty: solo lo que cambió.
        $this->assertSame(['operation_discount_pct'], array_keys($activity->properties['attributes']));
    }

    public function test_la_bitacora_enmascara_el_rtn(): void
    {
        $supplier = EdtSupplier::factory()->create(['rtn' => '05011990123456']);
        $supplier->update(['rtn' => '08011985654321']);

        $activity = $this->lastActivityFor($supplier);

        $this->assertSame('**********3456', $activity->properties['old']['rtn']);
        $this->assertSame('**********4321', $activity->properties['attributes']['rtn']);
        $this->assertStringNotContainsString('08011985654321', $activity->properties->toJson());
    }

    private function lastActivityFor(EdtSupplier $supplier): Activity
    {
        return Activity::query()
            ->where('subject_type', $supplier->getMorphClass())
            ->where('subject_id', $supplier->id)
            ->latest('id')
            ->firstOrFail();
    }
}
