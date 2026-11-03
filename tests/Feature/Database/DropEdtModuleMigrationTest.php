<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Limpieza del módulo EDT (2026-11-03): el EDT se fue a su propio proyecto.
 *
 * Lo importante es lo que NO toca: permisos, roles y usuarios de Jaremar
 * quedan igual, y donde el EDT nunca corrió (producción, CI) no hace nada.
 */
class DropEdtModuleMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_03_100000_drop_edt_module.php';

    /**
     * Corre la migración tal cual la ejecuta `php artisan migrate` en
     * pruebas — el archivo real, no una copia de su lógica.
     */
    private function correrLimpieza(): void
    {
        (require base_path(self::MIGRATION))->up();
    }

    /**
     * Lo que dejó el EDT en pruebas: tablas, una secuencia suelta, permisos,
     * el rol de vendedor y las filas de sus migraciones.
     */
    private function dejarRestosDelEdt(): void
    {
        Schema::create('edt_suppliers', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('edt_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('edt_suppliers');
        });
        Schema::create('hn_departments', function (Blueprint $table) {
            $table->id();
        });
        DB::statement('CREATE SEQUENCE edt_order_number_seq');

        DB::table('migrations')->insert([
            ['migration' => '2026_10_03_200000_create_edt_suppliers_table', 'batch' => 99],
            ['migration' => '2026_10_06_100000_create_edt_orders_tables', 'batch' => 99],
        ]);
    }

    public function test_borra_tablas_secuencias_y_filas_de_migraciones_del_edt(): void
    {
        $this->dejarRestosDelEdt();

        $this->correrLimpieza();

        foreach (['edt_suppliers', 'edt_products', 'hn_departments'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} debería haberse borrado.");
        }
        $this->assertFalse(
            DB::table('information_schema.sequences')->where('sequence_name', 'edt_order_number_seq')->exists()
        );
        $this->assertSame(
            0,
            DB::table('migrations')->whereIn('migration', [
                '2026_10_03_200000_create_edt_suppliers_table',
                '2026_10_06_100000_create_edt_orders_tables',
            ])->count()
        );
        // Las tablas de Jaremar siguen ahí.
        foreach (['invoices', 'invoice_lines', 'manifests', 'deposits', 'users'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} no se debe tocar.");
        }
    }

    public function test_borra_los_permisos_del_edt_y_deja_los_de_jaremar(): void
    {
        $encargado = Role::create(['name' => 'encargado', 'guard_name' => 'web']);
        foreach (['ViewAny:EdtProduct', 'Review:EdtOrder', 'ViewAny:Invoice', 'Close:Manifest'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        $encargado->givePermissionTo(['Review:EdtOrder', 'ViewAny:Invoice', 'Close:Manifest']);

        $this->correrLimpieza();

        $this->assertSame(
            ['Close:Manifest', 'ViewAny:Invoice'],
            Permission::query()->orderBy('name')->pluck('name')->all()
        );
        $this->assertEqualsCanonicalizing(
            ['ViewAny:Invoice', 'Close:Manifest'],
            $encargado->fresh()->permissions->pluck('name')->all()
        );
    }

    public function test_quita_el_rol_de_vendedor_y_desactiva_solo_a_quien_no_tenia_otro_rol(): void
    {
        Role::create(['name' => 'vendedor_edt', 'guard_name' => 'web']);
        Role::create(['name' => 'encargado', 'guard_name' => 'web']);

        $soloVendedor = User::factory()->create(['is_active' => true]);
        $soloVendedor->assignRole('vendedor_edt');

        $vendedorYEncargado = User::factory()->create(['is_active' => true]);
        $vendedorYEncargado->assignRole(['vendedor_edt', 'encargado']);

        $encargado = User::factory()->create(['is_active' => true]);
        $encargado->assignRole('encargado');

        $this->correrLimpieza();

        $this->assertFalse(Role::query()->where('name', 'vendedor_edt')->exists());

        $this->assertFalse($soloVendedor->fresh()->is_active);
        $this->assertSame([], $soloVendedor->fresh()->getRoleNames()->all());

        $this->assertTrue($vendedorYEncargado->fresh()->is_active);
        $this->assertSame(['encargado'], $vendedorYEncargado->fresh()->getRoleNames()->all());

        $this->assertTrue($encargado->fresh()->is_active);
        $this->assertSame(['encargado'], $encargado->fresh()->getRoleNames()->all());
    }

    public function test_sin_restos_del_edt_no_hace_nada_y_se_puede_correr_dos_veces(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        Permission::create(['name' => 'ViewAny:Invoice', 'guard_name' => 'web']);
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole('admin');
        $migraciones = DB::table('migrations')->count();

        $this->correrLimpieza();
        $this->correrLimpieza();

        $this->assertSame(1, Permission::query()->count());
        $this->assertSame(['admin'], $usuario->fresh()->getRoleNames()->all());
        $this->assertTrue($usuario->fresh()->is_active);
        $this->assertSame($migraciones, DB::table('migrations')->count());
    }
}
