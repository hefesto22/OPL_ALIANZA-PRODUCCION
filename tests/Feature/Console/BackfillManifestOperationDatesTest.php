<?php

namespace Tests\Feature\Console;

use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests del comando manifests:backfill-operation-dates.
 *
 * Corrige el desfase histórico donde manifests.date quedaba con la
 * fecha de captura en vez de max(invoice_date) de las facturas.
 *
 * Cubrimos:
 *  - dry-run no escribe a BD
 *  - ejecución real actualiza solo los manifests desfasados
 *  - manifests sin facturas se omiten (no se rompe el comando)
 *  - manifests ya correctos no se tocan
 *  - idempotencia: correr 2 veces no produce efecto adicional
 *
 * Postgres real, no SQLite — porque chunkById y los agregados (MAX)
 * tienen comportamiento sutilmente distinto entre motores.
 */
class BackfillManifestOperationDatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fecha fija para que invoice_date no varíe entre corridas.
        Carbon::setTestNow(
            Carbon::create(2026, 5, 20, 12, 0, 0, 'America/Tegucigalpa')
        );

        Supplier::factory()->create(['is_active' => true]);
        Warehouse::factory()->oac()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_does_not_modify_database(): void
    {
        // Manifest desfasado: date='2026-05-18' pero la factura es del 2026-05-15.
        $manifest = Manifest::factory()->create(['date' => '2026-05-18']);
        Invoice::factory()->for($manifest)->create(['invoice_date' => '2026-05-15']);

        $this->artisan('manifests:backfill-operation-dates', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN')
            ->assertSuccessful();

        // El date NO cambió en BD.
        $this->assertSame('2026-05-18', $manifest->fresh()->date->toDateString());
    }

    public function test_real_run_updates_manifest_date_to_max_invoice_date(): void
    {
        $manifest = Manifest::factory()->create(['date' => '2026-05-18']);

        // 3 facturas con fechas distintas — la MAX es 2026-05-17.
        Invoice::factory()->for($manifest)->create(['invoice_date' => '2026-05-15']);
        Invoice::factory()->for($manifest)->create(['invoice_date' => '2026-05-17']);
        Invoice::factory()->for($manifest)->create(['invoice_date' => '2026-05-16']);

        $this->artisan('manifests:backfill-operation-dates')->assertSuccessful();

        $this->assertSame('2026-05-17', $manifest->fresh()->date->toDateString());
    }

    public function test_manifest_already_correct_is_not_touched(): void
    {
        // Date ya coincide con max(invoice_date) → no debe contar como update.
        $manifest = Manifest::factory()->create(['date' => '2026-05-15']);
        Invoice::factory()->for($manifest)->create(['invoice_date' => '2026-05-15']);

        $this->artisan('manifests:backfill-operation-dates')
            ->expectsOutputToContain('Sin cambios necesarios')
            ->assertSuccessful();

        $this->assertSame('2026-05-15', $manifest->fresh()->date->toDateString());
    }

    public function test_manifest_without_invoices_is_skipped(): void
    {
        $manifest = Manifest::factory()->create(['date' => '2026-05-18']);
        // Sin facturas asociadas.

        $this->artisan('manifests:backfill-operation-dates')
            ->expectsOutputToContain('Sin facturas')
            ->assertSuccessful();

        // Date sin cambio.
        $this->assertSame('2026-05-18', $manifest->fresh()->date->toDateString());
    }

    public function test_is_idempotent_when_run_twice(): void
    {
        $manifest = Manifest::factory()->create(['date' => '2026-05-18']);
        Invoice::factory()->for($manifest)->create(['invoice_date' => '2026-05-15']);

        // Primera corrida: aplica el cambio.
        $this->artisan('manifests:backfill-operation-dates')->assertSuccessful();
        $this->assertSame('2026-05-15', $manifest->fresh()->date->toDateString());

        // Segunda corrida: ya está correcto, debería reportar 0 updates.
        $this->artisan('manifests:backfill-operation-dates')
            ->expectsOutputToContain('Sin cambios necesarios')
            ->assertSuccessful();

        $this->assertSame('2026-05-15', $manifest->fresh()->date->toDateString());
    }

    public function test_handles_multiple_manifests_with_mixed_states(): void
    {
        // m1: desfasado → se actualiza
        $m1 = Manifest::factory()->create(['date' => '2026-05-18']);
        Invoice::factory()->for($m1)->create(['invoice_date' => '2026-05-10']);

        // m2: correcto → no se toca
        $m2 = Manifest::factory()->create(['date' => '2026-05-15']);
        Invoice::factory()->for($m2)->create(['invoice_date' => '2026-05-15']);

        // m3: sin facturas → se omite
        $m3 = Manifest::factory()->create(['date' => '2026-05-19']);

        $this->artisan('manifests:backfill-operation-dates')->assertSuccessful();

        $this->assertSame('2026-05-10', $m1->fresh()->date->toDateString());
        $this->assertSame('2026-05-15', $m2->fresh()->date->toDateString());
        $this->assertSame('2026-05-19', $m3->fresh()->date->toDateString()); // sin cambio
    }

    public function test_chunk_option_processes_in_smaller_batches(): void
    {
        // Crear varios manifests para forzar más de un chunk.
        for ($i = 0; $i < 5; $i++) {
            $m = Manifest::factory()->create(['date' => '2026-05-18']);
            Invoice::factory()->for($m)->create(['invoice_date' => '2026-05-10']);
        }

        $this->artisan('manifests:backfill-operation-dates', ['--chunk' => 2])
            ->assertSuccessful();

        $this->assertSame(
            5,
            Manifest::where('date', '2026-05-10')->count()
        );
    }
}
