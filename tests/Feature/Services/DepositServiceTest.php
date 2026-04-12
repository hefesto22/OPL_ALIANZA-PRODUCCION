<?php

namespace Tests\Feature\Services;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests para DepositService.
 *
 * El flujo de depósitos es el eslabón financiero del sistema: si el
 * DepositService falla, el `difference` del manifiesto no cuadra y
 * no se puede cerrar — eso frena las operaciones de las bodegas.
 *
 * Todos los tests golpean Postgres real con RefreshDatabase. Se crea
 * un manifiesto con una factura de HNL 1,000 para tener un saldo
 * pendiente real (total_to_deposit = 1000 después de recalculateTotals).
 */
class DepositServiceTest extends TestCase
{
    use RefreshDatabase;

    private Manifest   $manifest;
    private Warehouse  $warehouse;
    private User       $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin',       'guard_name' => 'web']);

        $supplier        = Supplier::factory()->create(['is_active' => true]);
        $this->warehouse = Warehouse::factory()->oac()->create();
        $this->user      = User::factory()->create();

        // Manifiesto abierto con 1 factura de HNL 1,000
        $this->manifest = Manifest::factory()->create([
            'supplier_id' => $supplier->id,
            'status'      => 'imported',
        ]);

        Invoice::factory()->create([
            'manifest_id'  => $this->manifest->id,
            'warehouse_id' => $this->warehouse->id,
            'total'        => 1000.00,
        ]);

        // Inicializar total_to_deposit correctamente
        $this->manifest->recalculateTotals();
        $this->manifest->refresh();
    }

    private function service(): DepositService
    {
        return $this->app->make(DepositService::class);
    }

    private function depositData(array $overrides = []): array
    {
        return array_merge([
            'amount'       => 500.00,
            'deposit_date' => now()->toDateString(),
            'bank'         => 'BAC',
            'reference'    => 'REF-001',
            'observations' => null,
        ], $overrides);
    }

    // ═══════════════════════════════════════════════════════════════
    //  createDeposit
    // ═══════════════════════════════════════════════════════════════

    public function test_create_deposit_persists_and_recalculates_totals(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 400.00]),
            $this->user->id,
        );

        $this->assertInstanceOf(Deposit::class, $deposit);
        $this->assertEquals(400.00, (float) $deposit->amount);
        $this->assertSame($this->manifest->id, $deposit->manifest_id);
        $this->assertSame($this->user->id, $deposit->created_by);

        // Totales del manifiesto recalculados
        $this->manifest->refresh();
        $this->assertEquals(400.00, (float) $this->manifest->total_deposited);
        $this->assertEquals(600.00, (float) $this->manifest->difference);
    }

    public function test_create_deposit_on_closed_manifest_throws(): void
    {
        $this->manifest->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(ValidationException::class);

        $this->service()->createDeposit(
            $this->manifest->refresh(),
            $this->depositData(),
            $this->user->id,
        );
    }

    public function test_create_deposit_exceeding_pending_amount_throws(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 1500.00]),  // pending es 1000
            $this->user->id,
        );
    }

    public function test_create_deposit_with_receipt_image_sets_uploaded_at(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['receipt_image' => 'deposits/receipt-001.jpg']),
            $this->user->id,
        );

        $this->assertNotNull($deposit->receipt_image_uploaded_at);
        $this->assertSame('deposits/receipt-001.jpg', $deposit->receipt_image);
    }

    public function test_create_deposit_penny_margin_is_accepted(): void
    {
        // Depositar exactamente total_to_deposit + 0.01 (margen de redondeo)
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 1000.01]),
            $this->user->id,
        );

        $this->assertEquals(1000.01, (float) $deposit->amount);
    }

    public function test_create_deposit_beyond_penny_margin_throws(): void
    {
        $this->expectException(ValidationException::class);

        // 1000.02 excede el margen de 0.01
        $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 1000.02]),
            $this->user->id,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  updateDeposit
    // ═══════════════════════════════════════════════════════════════

    public function test_update_deposit_changes_amount_and_recalculates(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 300.00]),
            $this->user->id,
        );

        $updated = $this->service()->updateDeposit(
            $deposit->refresh(),
            $this->depositData(['amount' => 600.00]),
            $this->user->id,
        );

        $this->assertEquals(600.00, (float) $updated->amount);

        $this->manifest->refresh();
        $this->assertEquals(600.00, (float) $this->manifest->total_deposited);
        $this->assertEquals(400.00, (float) $this->manifest->difference);
    }

    public function test_update_deposit_same_amount_does_not_throw(): void
    {
        // Crear depósito de 1000 (todo el pendiente)
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 1000.00]),
            $this->user->id,
        );

        // Editar sin cambiar monto — no debería rechazar porque se excluye
        // el propio depósito del cálculo de pendiente
        $updated = $this->service()->updateDeposit(
            $deposit->refresh(),
            $this->depositData(['amount' => 1000.00, 'bank' => 'FICOHSA']),
            $this->user->id,
        );

        $this->assertSame('FICOHSA', $updated->bank);
    }

    public function test_update_deposit_on_closed_manifest_throws(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 200.00]),
            $this->user->id,
        );

        $this->manifest->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(ValidationException::class);

        $this->service()->updateDeposit(
            $deposit->refresh(),
            $this->depositData(['amount' => 300.00]),
            $this->user->id,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  deleteDeposit
    // ═══════════════════════════════════════════════════════════════

    public function test_delete_deposit_soft_deletes_and_recalculates(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 700.00]),
            $this->user->id,
        );

        $this->service()->deleteDeposit($deposit->refresh());

        $this->assertSoftDeleted('deposits', ['id' => $deposit->id]);

        $this->manifest->refresh();
        $this->assertEquals(0.00, (float) $this->manifest->total_deposited);
        $this->assertEquals(1000.00, (float) $this->manifest->difference);
    }

    public function test_delete_deposit_on_closed_manifest_throws(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 200.00]),
            $this->user->id,
        );

        $this->manifest->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(ValidationException::class);

        $this->service()->deleteDeposit($deposit->refresh());
    }

    // ═══════════════════════════════════════════════════════════════
    //  Getters
    // ═══════════════════════════════════════════════════════════════

    public function test_get_total_deposited_sums_all_deposits(): void
    {
        $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 200.00]),
            $this->user->id,
        );
        $this->service()->createDeposit(
            $this->manifest->refresh(),
            $this->depositData(['amount' => 300.00, 'reference' => 'REF-002']),
            $this->user->id,
        );

        $total = $this->service()->getTotalDeposited($this->manifest);

        $this->assertEquals(500.00, $total);
    }

    public function test_get_pending_amount_returns_remaining_balance(): void
    {
        $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 750.00]),
            $this->user->id,
        );

        $pending = $this->service()->getPendingAmount($this->manifest->refresh());

        $this->assertEquals(250.00, $pending);
    }

    public function test_get_pending_amount_floors_at_zero(): void
    {
        // Depositar todo + margen
        $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 1000.01]),
            $this->user->id,
        );

        $pending = $this->service()->getPendingAmount($this->manifest->refresh());

        $this->assertEquals(0.0, $pending);
    }
}
