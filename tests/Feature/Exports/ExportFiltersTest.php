<?php

namespace Tests\Feature\Exports;

use App\Exports\DepositsExport;
use App\Exports\ManifestsExport;
use App\Exports\ReturnsExport;
use App\Models\Deposit;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke tests para los filtros de fecha/status en cada Export.
 *
 * Valor real: estos tests atrapan bugs como el de DepositsExport que
 * usaba `$deposit->notes` cuando el campo real es `observations` —
 * bug silencioso que mostraba '—' por meses en producción.
 *
 * No pruebo el formato de cada celda del Excel (eso es frágil y
 * cambia con cada ajuste de diseño). Solo verifico que `query()`
 * filtra las filas correctas cuando se aplican las combinaciones
 * de filtros reales que usa Filament.
 */
class ExportFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = Warehouse::factory()->oac()->create();
        Supplier::factory()->create(['is_active' => true]);
    }

    public function test_manifests_export_filters_by_status(): void
    {
        Manifest::factory()->create(['status' => 'imported']);
        Manifest::factory()->create(['status' => 'closed']);

        $imported = (new ManifestsExport(status: 'imported'))->query()->get();

        $this->assertCount(1, $imported);
        $this->assertSame('imported', $imported->first()->status);
    }

    public function test_manifests_export_filters_by_date_range(): void
    {
        Manifest::factory()->create(['date' => '2026-01-15']);
        Manifest::factory()->create(['date' => '2026-02-15']);
        Manifest::factory()->create(['date' => '2026-03-15']);

        $febOnly = (new ManifestsExport(dateFrom: '2026-02-01', dateTo: '2026-02-28'))
            ->query()
            ->get();

        $this->assertCount(1, $febOnly);
    }

    public function test_deposits_export_filters_by_date_range(): void
    {
        $manifest = Manifest::factory()->create();

        Deposit::create([
            'manifest_id' => $manifest->id,
            'amount' => 100.00,
            'deposit_date' => '2026-01-10',
            'bank' => 'BANPAIS',
        ]);
        Deposit::create([
            'manifest_id' => $manifest->id,
            'amount' => 200.00,
            'deposit_date' => '2026-02-10',
            'bank' => 'BANPAIS',
        ]);

        $janOnly = (new DepositsExport(dateFrom: '2026-01-01', dateTo: '2026-01-31'))
            ->query()
            ->get();

        $this->assertCount(1, $janOnly);
        $this->assertEquals(100.00, $janOnly->first()->amount);
    }

    public function test_deposits_export_map_reads_observations_not_notes(): void
    {
        // Regression test: DepositsExport antes leía $deposit->notes (campo
        // inexistente → siempre '—'). Ahora lee observations.
        $manifest = Manifest::factory()->create();
        $deposit = Deposit::create([
            'manifest_id' => $manifest->id,
            'amount' => 100.00,
            'deposit_date' => '2026-01-10',
            'bank' => 'BANPAIS',
            'observations' => 'Texto de prueba',
        ]);

        $row = (new DepositsExport)->map($deposit->fresh(['manifest', 'createdBy']));

        // La columna de observations es la 8va (índice 7, 0-based).
        $this->assertSame('Texto de prueba', $row[7]);
    }

    public function test_returns_export_filters_by_status_and_warehouse(): void
    {
        $pending = InvoiceReturn::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'status' => 'pending',
        ]);
        InvoiceReturn::factory()->create([
            'warehouse_id' => $this->warehouse->id,
            'status' => 'approved',
        ]);
        InvoiceReturn::factory()->create([
            'status' => 'pending', // otra bodega
        ]);

        $onlyPendingOfWarehouse = (new ReturnsExport(
            status: 'pending',
            warehouseId: $this->warehouse->id,
        ))->query()->pluck('id')->toArray();

        $this->assertCount(1, $onlyPendingOfWarehouse);
        $this->assertContains($pending->id, $onlyPendingOfWarehouse);
    }
}
