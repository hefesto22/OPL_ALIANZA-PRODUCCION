<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\ManifestWarehouseTotal;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A qué bodega se le imputa un depósito, y cómo eso alimenta el desglose
 * por bodega del manifiesto.
 *
 * El caso que originó todo esto: desde que existen manifiestos multi-bodega
 * (traslado de facturas del 27/08), el encargado de Santa Bárbara veía como
 * suyo el saldo del manifiesto ENTERO. Facturas y devoluciones ya se sabían
 * repartir; los depósitos no, porque `deposits` solo conocía el manifiesto.
 *
 * Lo que este archivo protege, en orden de importancia:
 *   1. Que la atribución no adivine. Un depósito ambiguo se guarda SIN bodega
 *      antes que con la bodega equivocada.
 *   2. Que el desglose por bodega cuadre.
 *   3. Que el saldo GLOBAL del manifiesto no haya cambiado — el sobrepago y
 *      el cierre siguen mirando el manifiesto entero, y eso se prometió
 *      explícitamente al diseñar esto.
 */
class DepositWarehouseAttributionTest extends TestCase
{
    use RefreshDatabase;

    private DepositService $service;

    private Warehouse $oai;

    private Warehouse $oas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(DepositService::class);
        $this->oai = Warehouse::factory()->oai()->create();
        $this->oas = Warehouse::factory()->oas()->create();
    }

    /**
     * Manifiesto con facturas de las dos bodegas: OAI 1,000 y OAS 500.
     */
    private function multiWarehouseManifest(): Manifest
    {
        $manifest = Manifest::factory()->create(['warehouse_id' => null]);

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->oai->id,
            'total' => 1000,
            'client_id' => 'CLI001',
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->oas->id,
            'total' => 500,
            'client_id' => 'CLI002',
        ]);

        $manifest->recalculateTotals();

        return $manifest->refresh();
    }

    private function singleWarehouseManifest(): Manifest
    {
        $manifest = Manifest::factory()->create(['warehouse_id' => null]);

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->oas->id,
            'total' => 500,
        ]);

        $manifest->recalculateTotals();

        return $manifest->refresh();
    }

    private function totalsFor(Manifest $manifest, Warehouse $warehouse): ?ManifestWarehouseTotal
    {
        return ManifestWarehouseTotal::query()
            ->where('manifest_id', $manifest->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();
    }

    // ── Atribución ───────────────────────────────────────────────────────

    public function test_a_single_warehouse_manifest_needs_nobody_to_pick(): void
    {
        // El 95% de los manifiestos. Nadie tiene que ver un campo nuevo:
        // no hay ambigüedad posible, ni siquiera para un usuario global.
        $manifest = $this->singleWarehouseManifest();
        $global = User::factory()->create();

        $this->assertSame(
            $this->oas->id,
            $this->service->resolveWarehouseId($manifest, $global)
        );
    }

    public function test_a_warehouse_user_deposits_for_their_own_warehouse(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $deOas = User::factory()->forWarehouse($this->oas)->create();

        $this->assertSame(
            $this->oas->id,
            $this->service->resolveWarehouseId($manifest, $deOas)
        );
    }

    public function test_a_global_user_in_a_multi_warehouse_manifest_has_to_be_asked(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $global = User::factory()->create();

        // NULL no es un fallo: es la señal de que hay que preguntar. Adivinar
        // acá metería plata en la bodega equivocada dentro de un reporte
        // financiero, y eso no se ve hasta que alguien reclama.
        $this->assertNull($this->service->resolveWarehouseId($manifest, $global));
    }

    public function test_a_user_covering_both_warehouses_has_to_be_asked(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $ambos = User::factory()->forWarehouse([$this->oai, $this->oas])->create();

        $this->assertNull($this->service->resolveWarehouseId($manifest, $ambos));
    }

    public function test_the_options_offered_are_only_the_warehouses_of_that_manifest(): void
    {
        // Una tercera bodega que NO participa del manifiesto no debe ofrecerse:
        // imputarle un depósito generaría una fila fantasma en el desglose.
        Warehouse::factory()->oac()->create();

        $manifest = $this->multiWarehouseManifest();
        $global = User::factory()->create();

        $options = $this->service->warehouseOptions($manifest, $global);

        $this->assertEqualsCanonicalizing(['OAI', 'OAS'], array_values($options));
    }

    // ── Alta del depósito ────────────────────────────────────────────────

    public function test_the_deposit_is_attributed_without_asking_when_it_is_deducible(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $deOas = User::factory()->forWarehouse($this->oas)->create();

        $deposit = $this->service->createDeposit($manifest, [
            'amount' => 500,
            'deposit_date' => today()->toDateString(),
        ], $deOas->id);

        $this->assertEquals($this->oas->id, $deposit->warehouse_id);
    }

    public function test_the_warehouse_sent_by_the_form_wins(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $global = User::factory()->create();

        $deposit = $this->service->createDeposit($manifest, [
            'amount' => 1000,
            'deposit_date' => today()->toDateString(),
            'warehouse_id' => $this->oai->id,
        ], $global->id);

        $this->assertEquals($this->oai->id, $deposit->warehouse_id);
    }

    public function test_an_ambiguous_deposit_is_saved_without_warehouse_instead_of_being_rejected(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $global = User::factory()->create();

        $deposit = $this->service->createDeposit($manifest, [
            'amount' => 300,
            'deposit_date' => today()->toDateString(),
        ], $global->id);

        // Sin bodega, pero registrado: la plata entró al banco igual. El panel
        // lo declara aparte para que alguien lo corrija.
        $this->assertNull($deposit->warehouse_id);
        $this->assertEquals(300.00, (float) $manifest->fresh()->total_deposited);
    }

    // ── Desglose por bodega ──────────────────────────────────────────────

    public function test_the_per_warehouse_breakdown_counts_each_deposit_where_it_belongs(): void
    {
        $manifest = $this->multiWarehouseManifest();
        $deOas = User::factory()->forWarehouse($this->oas)->create();

        $this->service->createDeposit($manifest, [
            'amount' => 500,
            'deposit_date' => today()->toDateString(),
        ], $deOas->id);

        $oas = $this->totalsFor($manifest, $this->oas);
        $oai = $this->totalsFor($manifest, $this->oai);

        // OAS depositó lo suyo y queda a cero.
        $this->assertEquals(500.00, (float) $oas->total_deposited);
        $this->assertEquals(0.00, (float) $oas->difference);

        // OAI no depositó nada y sigue debiendo sus 1,000 — antes de este
        // cambio ambas bodegas veían la deuda de la otra como propia.
        $this->assertEquals(0.00, (float) $oai->total_deposited);
        $this->assertEquals(1000.00, (float) $oai->difference);
    }

    public function test_a_deposit_without_warehouse_is_not_gifted_to_anyone(): void
    {
        $manifest = $this->multiWarehouseManifest();

        Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => null,
            'amount' => 400,
        ]);

        $manifest->recalculateTotals();

        $this->assertEquals(0.00, (float) $this->totalsFor($manifest, $this->oai)->total_deposited);
        $this->assertEquals(0.00, (float) $this->totalsFor($manifest, $this->oas)->total_deposited);

        // Pero SÍ cuenta en el total del manifiesto: la plata existe.
        $this->assertEquals(400.00, (float) $manifest->fresh()->total_deposited);
    }

    public function test_a_cancelled_deposit_does_not_count_for_its_warehouse(): void
    {
        $manifest = $this->multiWarehouseManifest();

        Deposit::factory()->forWarehouse($this->oas)->create([
            'manifest_id' => $manifest->id,
            'amount' => 500,
            'cancelled_at' => now(),
        ]);

        $manifest->recalculateTotals();

        $this->assertEquals(0.00, (float) $this->totalsFor($manifest, $this->oas)->total_deposited);
        $this->assertEquals(500.00, (float) $this->totalsFor($manifest, $this->oas)->difference);
    }

    // ── El saldo global no cambió ────────────────────────────────────────

    public function test_the_global_balance_still_governs_and_includes_unattributed_deposits(): void
    {
        // Contrato explícito de este cambio: el desglose por bodega es
        // informativo. El sobrepago, el progreso y el cierre siguen mirando el
        // manifiesto entero, así que el total global tiene que seguir sumando
        // TODO, incluida la plata que no se pudo atribuir.
        $manifest = $this->multiWarehouseManifest();

        Deposit::factory()->forWarehouse($this->oas)->create([
            'manifest_id' => $manifest->id,
            'amount' => 500,
        ]);
        Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => null,
            'amount' => 1000,
        ]);

        $manifest->recalculateTotals();
        $fresh = $manifest->fresh();

        $this->assertEquals(1500.00, (float) $fresh->total_to_deposit);
        $this->assertEquals(1500.00, (float) $fresh->total_deposited);
        $this->assertEquals(0.00, (float) $fresh->difference);

        // La suma por bodega NO da el global, y eso es correcto: los 1,000 sin
        // atribuir viven solo a nivel manifiesto.
        $sumaPorBodega = (float) ManifestWarehouseTotal::query()
            ->where('manifest_id', $manifest->id)
            ->sum('total_deposited');

        $this->assertEquals(500.00, $sumaPorBodega);
    }
}
