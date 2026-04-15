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
}
