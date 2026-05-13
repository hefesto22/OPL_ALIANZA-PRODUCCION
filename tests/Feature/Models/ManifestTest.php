<?php

namespace Tests\Feature\Models;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ManifestWarehouseTotal;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests para la lógica de negocio del modelo Manifest.
 *
 * El Manifest es el corazón contable del sistema: cada día llega un
 * manifiesto con las facturas de las 3 bodegas (OAC/OAS/OAO) y hay que
 * cuadrar depósitos vs ventas vs devoluciones. Bug aquí = diferencias
 * reales de dinero al cerrar el día. Por eso estos tests:
 *
 *   1. golpean Postgres real con RefreshDatabase (no mocks) para validar
 *      que las queries con CASE WHEN producen los mismos resultados que
 *      el código PHP espera;
 *   2. usan las 3 bodegas canónicas vía WarehouseFactory::oac/oas/oao
 *      para detectar regresiones específicas de Hozana;
 *   3. cubren tanto los agregados globales (recalculateTotals) como los
 *      per-warehouse (recalculateWarehouseTotals) porque son dos queries
 *      distintas que pueden desincronizarse.
 */
class ManifestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Construye un manifiesto "en estado listo para cerrar": diferencia en
     * cero y al menos un lempira en total_to_deposit. Los tests de
     * isReadyToClose/close/reopen lo usan como punto de partida y luego
     * rompen una sola precondición a la vez.
     */
    private function balancedManifest(array $overrides = []): Manifest
    {
        return Manifest::factory()->create(array_merge([
            'status' => 'imported',
            'total_invoices' => 1000,
            'total_returns' => 0,
            'total_to_deposit' => 1000,
            'total_deposited' => 1000,
            'difference' => 0,
        ], $overrides));
    }

    // ── isReadyToClose / close / reopen ──────────────────────────────────

    public function test_is_not_ready_to_close_when_already_closed(): void
    {
        $manifest = $this->balancedManifest(['status' => 'closed']);

        $this->assertTrue($manifest->isClosed());
        $this->assertFalse($manifest->isReadyToClose());
    }

    public function test_is_not_ready_to_close_when_difference_is_not_zero(): void
    {
        $manifest = $this->balancedManifest([
            'total_deposited' => 900,
            'difference' => 100,
        ]);

        $this->assertFalse($manifest->isReadyToClose());
    }

    public function test_is_not_ready_to_close_when_total_to_deposit_is_zero(): void
    {
        // Manifiesto completamente devuelto: no hay nada que cuadrar.
        // Cerrar un manifiesto así no tiene sentido contable, por eso
        // isReadyToClose() lo bloquea.
        $manifest = $this->balancedManifest([
            'total_invoices' => 500,
            'total_returns' => 500,
            'total_to_deposit' => 0,
            'total_deposited' => 0,
            'difference' => 0,
        ]);

        $this->assertFalse($manifest->isReadyToClose());
    }

    public function test_is_not_ready_to_close_when_there_are_pending_returns(): void
    {
        $manifest = $this->balancedManifest();

        // Una devolución pendiente deja al manifiesto en "limbo": si se
        // cierra, ya no se puede aprobar/rechazar sin reabrirlo.
        InvoiceReturn::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $manifest->warehouse_id,
            'status' => 'pending',
            'total' => 0,
        ]);

        $this->assertFalse($manifest->isReadyToClose());
    }

    public function test_is_ready_to_close_when_balanced_with_approved_returns_only(): void
    {
        $manifest = $this->balancedManifest();

        // Devoluciones aprobadas NO bloquean el cierre: ya pasaron por
        // revisión y están reflejadas en los totales.
        InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $manifest->warehouse_id,
            'total' => 0,
        ]);

        $this->assertTrue($manifest->isReadyToClose());
    }

    public function test_close_marks_manifest_with_user_and_timestamp(): void
    {
        $manifest = $this->balancedManifest();
        $user = User::factory()->create();

        app(\App\Services\ManifestService::class)
            ->closeManifest($manifest, $user->id);

        $manifest->refresh();
        $this->assertSame('closed', $manifest->status);
        $this->assertSame($user->id, $manifest->closed_by);
        $this->assertNotNull($manifest->closed_at);
    }

    public function test_reopen_clears_closed_metadata(): void
    {
        $user = User::factory()->create();
        $manifest = Manifest::factory()->closed()->create([
            'closed_by' => $user->id,
        ]);

        app(\App\Services\ManifestService::class)
            ->reopenManifest($manifest);

        $manifest->refresh();
        $this->assertSame('imported', $manifest->status);
        $this->assertNull($manifest->closed_by);
        $this->assertNull($manifest->closed_at);
    }

    // ── getInvoicesSummary ──────────────────────────────────────────────

    public function test_invoices_summary_groups_by_status_with_counts_and_totals(): void
    {
        $manifest = Manifest::factory()->create();

        Invoice::factory()->count(2)->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $manifest->warehouse_id,
            'status' => 'imported',
            'total' => 500,
        ]);

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $manifest->warehouse_id,
            'status' => 'returned',
            'total' => 300,
        ]);

        $summary = $manifest->getInvoicesSummary();

        $this->assertArrayHasKey('imported', $summary);
        $this->assertArrayHasKey('returned', $summary);
        $this->assertSame(2, $summary['imported']['count']);
        $this->assertEqualsWithDelta(1000.0, $summary['imported']['total'], 0.01);
        $this->assertSame(1, $summary['returned']['count']);
        $this->assertEqualsWithDelta(300.0, $summary['returned']['total'], 0.01);
    }

    public function test_invoices_summary_filters_by_warehouse(): void
    {
        $manifest = Manifest::factory()->create();
        $oac = Warehouse::factory()->oac()->create();
        $oas = Warehouse::factory()->oas()->create();

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'status' => 'imported',
            'total' => 1000,
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oas->id,
            'status' => 'imported',
            'total' => 500,
        ]);

        $oacSummary = $manifest->getInvoicesSummary($oac->id);

        $this->assertSame(1, $oacSummary['imported']['count']);
        $this->assertEqualsWithDelta(1000.0, $oacSummary['imported']['total'], 0.01);
    }

    // ── recalculateTotals ───────────────────────────────────────────────

    public function test_recalculate_totals_excludes_invoices_without_warehouse_from_monetary_sum(): void
    {
        // Regresión protegida: las facturas en pending_warehouse (sin
        // warehouse_id asignado) NO deben sumar al total_invoices porque
        // aún no están confirmadas para una bodega. Sí deben aparecer en
        // clients_count porque su client_id es válido.
        $manifest = Manifest::factory()->create();
        $oac = Warehouse::factory()->oac()->create();

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 1500,
            'client_id' => 'CLI001',
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => null,
            'status' => 'pending_warehouse',
            'total' => 9999,  // NO debe sumar
            'client_id' => 'CLI002',
        ]);

        $manifest->recalculateTotals();
        $manifest->refresh();

        $this->assertEqualsWithDelta(1500.0, (float) $manifest->total_invoices, 0.01);
        $this->assertSame(1, $manifest->invoices_count);
        // Clientes únicos cuenta AMBAS facturas (la pending también tiene client_id).
        $this->assertSame(2, $manifest->clients_count);
    }

    public function test_recalculate_totals_counts_distinct_clients_across_warehouses(): void
    {
        $manifest = Manifest::factory()->create();
        $oac = Warehouse::factory()->oac()->create();
        $oas = Warehouse::factory()->oas()->create();

        // CLI001 aparece 2 veces (una en OAC, otra en OAS) → cuenta 1 sola vez
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 100,
            'client_id' => 'CLI001',
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oas->id,
            'total' => 200,
            'client_id' => 'CLI001',
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 300,
            'client_id' => 'CLI002',
        ]);

        $manifest->recalculateTotals();

        $this->assertSame(2, $manifest->fresh()->clients_count);
    }

    public function test_recalculate_totals_only_counts_approved_returns_in_monetary_total(): void
    {
        $manifest = Manifest::factory()->create();
        $oac = Warehouse::factory()->oac()->create();

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 5000,
        ]);

        // Una aprobada, una pendiente, una rechazada. Solo la aprobada
        // debe restar del total_to_deposit. Las 3 cuentan en returns_count.
        InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 800,
        ]);
        InvoiceReturn::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'status' => 'pending',
            'total' => 300,
        ]);
        InvoiceReturn::factory()->rejected()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 150,
        ]);

        $manifest->recalculateTotals();
        $manifest->refresh();

        $this->assertEqualsWithDelta(800.0, (float) $manifest->total_returns, 0.01);
        $this->assertSame(3, $manifest->returns_count);
        // total_to_deposit = 5000 - 800 = 4200 (solo resta la aprobada)
        $this->assertEqualsWithDelta(4200.0, (float) $manifest->total_to_deposit, 0.01);
    }

    public function test_recalculate_totals_computes_difference_from_invoices_returns_and_deposits(): void
    {
        $manifest = Manifest::factory()->create();
        $oac = Warehouse::factory()->oac()->create();
        $user = User::factory()->create();

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 10000,
        ]);
        InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 1000,
        ]);

        // Dos depósitos parciales que NO cuadran con total_to_deposit.
        Deposit::create([
            'manifest_id' => $manifest->id,
            'amount' => 5000,
            'deposit_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        Deposit::create([
            'manifest_id' => $manifest->id,
            'amount' => 2000,
            'deposit_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        $manifest->recalculateTotals();
        $manifest->refresh();

        $this->assertEqualsWithDelta(10000.0, (float) $manifest->total_invoices, 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $manifest->total_returns, 0.01);
        $this->assertEqualsWithDelta(9000.0, (float) $manifest->total_to_deposit, 0.01);
        $this->assertEqualsWithDelta(7000.0, (float) $manifest->total_deposited, 0.01);
        // difference = (10000 - 1000) - 7000 = 2000 — falta depositar
        $this->assertEqualsWithDelta(2000.0, (float) $manifest->difference, 0.01);
    }

    // ── recalculateWarehouseTotals (OAC/OAS/OAO) ────────────────────────

    public function test_recalculate_warehouse_totals_creates_one_row_per_warehouse_for_oac_oas_oao(): void
    {
        $manifest = Manifest::factory()->create();
        $oac = Warehouse::factory()->oac()->create();
        $oas = Warehouse::factory()->oas()->create();
        $oao = Warehouse::factory()->oao()->create();

        // OAC: 2 facturas (2 clientes distintos), 1 devolución aprobada.
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 1000,
            'client_id' => 'CLI001',
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 2000,
            'client_id' => 'CLI002',
        ]);
        InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 200,
        ]);

        // OAS: 1 factura, 1 devolución pendiente (NO suma a total_returns
        // pero sí a returns_count).
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oas->id,
            'total' => 4500,
            'client_id' => 'CLI003',
        ]);
        InvoiceReturn::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oas->id,
            'status' => 'pending',
            'total' => 500,
        ]);

        // OAO: 1 factura, sin devoluciones.
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oao->id,
            'total' => 800,
            'client_id' => 'CLI004',
        ]);

        $manifest->recalculateTotals();

        // Hay exactamente 1 fila por bodega.
        $this->assertSame(3, $manifest->warehouseTotals()->count());

        $oacTotal = ManifestWarehouseTotal::where('manifest_id', $manifest->id)
            ->where('warehouse_id', $oac->id)->first();
        $this->assertEqualsWithDelta(3000.0, (float) $oacTotal->total_invoices, 0.01);
        $this->assertSame(2, $oacTotal->invoices_count);
        $this->assertSame(2, $oacTotal->clients_count);
        $this->assertEqualsWithDelta(200.0, (float) $oacTotal->total_returns, 0.01);
        $this->assertSame(1, $oacTotal->returns_count);
        // total_to_deposit = 3000 - 200 = 2800
        $this->assertEqualsWithDelta(2800.0, (float) $oacTotal->total_to_deposit, 0.01);

        $oasTotal = ManifestWarehouseTotal::where('manifest_id', $manifest->id)
            ->where('warehouse_id', $oas->id)->first();
        $this->assertEqualsWithDelta(4500.0, (float) $oasTotal->total_invoices, 0.01);
        // La devolución pendiente NO suma a total_returns pero sí a returns_count
        $this->assertEqualsWithDelta(0.0, (float) $oasTotal->total_returns, 0.01);
        $this->assertSame(1, $oasTotal->returns_count);
        $this->assertEqualsWithDelta(4500.0, (float) $oasTotal->total_to_deposit, 0.01);

        $oaoTotal = ManifestWarehouseTotal::where('manifest_id', $manifest->id)
            ->where('warehouse_id', $oao->id)->first();
        $this->assertEqualsWithDelta(800.0, (float) $oaoTotal->total_invoices, 0.01);
        $this->assertSame(0, $oaoTotal->returns_count);
        $this->assertEqualsWithDelta(0.0, (float) $oaoTotal->total_returns, 0.01);
        $this->assertEqualsWithDelta(800.0, (float) $oaoTotal->total_to_deposit, 0.01);
    }

    public function test_recalculate_warehouse_totals_is_idempotent(): void
    {
        // Regresión protegida: llamar recalculateTotals() N veces debe
        // producir el mismo resultado (upsert en lugar de insert), porque
        // durante la importación de la API se llama varias veces en el
        // mismo manifest al procesar batches.
        $manifest = Manifest::factory()->create();
        $oac = Warehouse::factory()->oac()->create();

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 1234,
            'client_id' => 'CLI001',
        ]);

        $manifest->recalculateTotals();
        $manifest->recalculateTotals();
        $manifest->recalculateTotals();

        // Sigue habiendo exactamente 1 fila en manifest_warehouse_totals
        // para este manifiesto+bodega, no 3.
        $this->assertSame(1, ManifestWarehouseTotal::where('manifest_id', $manifest->id)
            ->where('warehouse_id', $oac->id)
            ->count());

        $row = ManifestWarehouseTotal::where('manifest_id', $manifest->id)->first();
        $this->assertEqualsWithDelta(1234.0, (float) $row->total_invoices, 0.01);
        $this->assertSame(1, $row->invoices_count);
    }

    public function test_recalculate_warehouse_totals_excludes_invoices_without_warehouse(): void
    {
        // Las facturas sin warehouse_id (pending_warehouse) NO deben
        // generar una fila fantasma con warehouse_id=NULL en
        // manifest_warehouse_totals — hay una FK NOT NULL que bloquearía
        // el insert, pero queremos confirmar que el whereNotNull() del
        // query las filtra ANTES de intentar insertar.
        $manifest = Manifest::factory()->create();
        $oac = Warehouse::factory()->oac()->create();

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $oac->id,
            'total' => 500,
            'client_id' => 'CLI001',
        ]);
        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => null,
            'status' => 'pending_warehouse',
            'total' => 999,
            'client_id' => 'CLI002',
        ]);

        $manifest->recalculateTotals();

        // Sólo la bodega OAC tiene fila; la factura sin bodega se ignora.
        $this->assertSame(1, $manifest->warehouseTotals()->count());
        $row = $manifest->warehouseTotals()->first();
        $this->assertSame($oac->id, $row->warehouse_id);
        $this->assertEqualsWithDelta(500.0, (float) $row->total_invoices, 0.01);
    }
}
