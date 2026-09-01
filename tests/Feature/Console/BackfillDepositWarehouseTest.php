<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\ManifestWarehouseTotal;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Backfill de la bodega en depósitos históricos.
 *
 * Lo que se protege: que el comando NO adivine. Deducir de más es peor que
 * deducir de menos — un depósito sin bodega se ve y se corrige; uno atribuido
 * a la bodega equivocada miente en silencio dentro de un reporte financiero.
 */
class BackfillDepositWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $oai;

    private Warehouse $oas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oai = Warehouse::factory()->oai()->create();
        $this->oas = Warehouse::factory()->oas()->create();
    }

    private function multiWarehouseManifest(): Manifest
    {
        $manifest = Manifest::factory()->create(['warehouse_id' => null]);

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->oai->id,
            'total' => 1000,
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->oas->id,
            'total' => 500,
        ]);

        $manifest->recalculateTotals();

        return $manifest->refresh();
    }

    public function test_it_attributes_what_it_can_deduce_from_who_registered_it(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $deOas = User::factory()->forWarehouse($this->oas)->create();

        $deposit = Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => null,
            'amount' => 500,
            'created_by' => $deOas->id,
        ]);

        $this->artisan('deposits:backfill-warehouse')->assertSuccessful();

        $this->assertEquals($this->oas->id, $deposit->fresh()->warehouse_id);
    }

    public function test_a_single_warehouse_manifest_is_attributed_regardless_of_who_registered_it(): void
    {
        $manifest = Manifest::factory()->create(['warehouse_id' => null]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->oas->id,
            'total' => 500,
        ]);
        $manifest->recalculateTotals();

        $global = User::factory()->create();
        $deposit = Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => null,
            'amount' => 500,
            'created_by' => $global->id,
        ]);

        $this->artisan('deposits:backfill-warehouse')->assertSuccessful();

        $this->assertEquals($this->oas->id, $deposit->fresh()->warehouse_id);
    }

    public function test_it_leaves_the_ambiguous_ones_alone_instead_of_guessing(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $global = User::factory()->create();

        $deposit = Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => null,
            'amount' => 300,
            'created_by' => $global->id,
        ]);

        $this->artisan('deposits:backfill-warehouse')
            ->expectsOutputToContain('Sin atribuir')
            ->assertSuccessful();

        $this->assertNull($deposit->fresh()->warehouse_id);
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $deOas = User::factory()->forWarehouse($this->oas)->create();

        $deposit = Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => null,
            'amount' => 500,
            'created_by' => $deOas->id,
        ]);

        $this->artisan('deposits:backfill-warehouse', ['--dry-run' => true])
            ->expectsOutputToContain('Dry-run')
            ->assertSuccessful();

        $this->assertNull($deposit->fresh()->warehouse_id);
    }

    public function test_it_recalculates_the_per_warehouse_totals_after_attributing(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $deOas = User::factory()->forWarehouse($this->oas)->create();

        Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => null,
            'amount' => 500,
            'created_by' => $deOas->id,
        ]);

        // Antes del backfill la plata no está imputada a nadie.
        $this->assertEquals(0.00, (float) ManifestWarehouseTotal::query()
            ->where('manifest_id', $manifest->id)
            ->where('warehouse_id', $this->oas->id)
            ->value('total_deposited'));

        $this->artisan('deposits:backfill-warehouse')->assertSuccessful();

        $oas = ManifestWarehouseTotal::query()
            ->where('manifest_id', $manifest->id)
            ->where('warehouse_id', $this->oas->id)
            ->first();

        $this->assertEquals(500.00, (float) $oas->total_deposited);
        $this->assertEquals(0.00, (float) $oas->difference);
    }

    public function test_it_is_idempotent(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $deOas = User::factory()->forWarehouse($this->oas)->create();

        $deposit = Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => null,
            'amount' => 500,
            'created_by' => $deOas->id,
        ]);

        $this->artisan('deposits:backfill-warehouse')->assertSuccessful();

        $this->artisan('deposits:backfill-warehouse')
            ->expectsOutputToContain('Depósitos sin bodega: 0')
            ->assertSuccessful();

        $this->assertEquals($this->oas->id, $deposit->fresh()->warehouse_id);
    }
}
