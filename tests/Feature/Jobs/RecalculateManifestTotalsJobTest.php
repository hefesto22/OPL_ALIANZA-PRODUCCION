<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RecalculateManifestTotalsJob;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests para RecalculateManifestTotalsJob.
 *
 * El Job es un wrapper delgado sobre recalculateTotals(), pero si
 * falla silenciosamente en la queue los totales quedan corruptos
 * hasta el próximo recálculo manual.
 */
class RecalculateManifestTotalsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin',       'guard_name' => 'web']);
    }

    public function test_job_recalculates_manifest_totals(): void
    {
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $warehouse = Warehouse::factory()->oac()->create();

        $manifest = Manifest::factory()->create([
            'supplier_id' => $supplier->id,
            'total_invoices' => 0,
            'invoices_count' => 0,
        ]);

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'total' => 1500.00,
        ]);

        // Totales aún en 0 antes del Job
        $this->assertEquals(0.0, (float) $manifest->total_invoices);

        // Ejecutar el Job sincrónicamente
        (new RecalculateManifestTotalsJob($manifest->id))->handle();

        $manifest->refresh();
        $this->assertEquals(1500.00, (float) $manifest->total_invoices);
        $this->assertSame(1, $manifest->invoices_count);
    }

    public function test_job_handles_nonexistent_manifest_gracefully(): void
    {
        // No debe lanzar excepción si el manifiesto fue borrado
        // entre que se encoló y se ejecutó.
        $job = new RecalculateManifestTotalsJob(999999);
        $job->handle();

        // Si llegamos aquí sin excepción, el test pasa
        $this->assertTrue(true);
    }

    public function test_job_is_idempotent(): void
    {
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $warehouse = Warehouse::factory()->oac()->create();

        $manifest = Manifest::factory()->create([
            'supplier_id' => $supplier->id,
        ]);

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'total' => 800.00,
        ]);

        // Ejecutar 2 veces — mismos totales
        (new RecalculateManifestTotalsJob($manifest->id))->handle();
        (new RecalculateManifestTotalsJob($manifest->id))->handle();

        $manifest->refresh();
        $this->assertEquals(800.00, (float) $manifest->total_invoices);
        $this->assertSame(1, $manifest->invoices_count);
    }

    public function test_job_implements_should_be_unique_until_processing(): void
    {
        // Contrato de unicidad: jobs pendientes para el mismo manifest se
        // deduplican. ShouldBeUniqueUntilProcessing libera el lock al iniciar
        // el handle — preserva la garantía "el último gana" para cambios
        // que lleguen DURANTE la ejecución de un recálculo en curso.
        $reflection = new \ReflectionClass(RecalculateManifestTotalsJob::class);

        $this->assertTrue(
            $reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing::class),
            'RecalculateManifestTotalsJob debe implementar ShouldBeUniqueUntilProcessing '.
            'para evitar acumular N jobs idénticos pendientes cuando llegan múltiples '.
            'devoluciones rápidas al mismo manifest.'
        );
    }

    public function test_job_unique_id_uses_manifest_id_as_scope(): void
    {
        // Verifica que el scope del lock es por manifest_id — jobs para
        // distintos manifests NO compiten, jobs del mismo manifest SÍ.
        $job1 = new RecalculateManifestTotalsJob(101);
        $job2 = new RecalculateManifestTotalsJob(101);
        $job3 = new RecalculateManifestTotalsJob(102);

        $this->assertSame($job1->uniqueId(), $job2->uniqueId(), 'Mismo manifest = mismo uniqueId');
        $this->assertNotSame($job1->uniqueId(), $job3->uniqueId(), 'Manifest distinto = uniqueId distinto');
        $this->assertSame('recalc-manifest:101', $job1->uniqueId());
    }
}
