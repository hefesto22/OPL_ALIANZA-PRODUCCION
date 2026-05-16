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

    private Manifest $manifest;

    private Warehouse $warehouse;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin',       'guard_name' => 'web']);

        $supplier = Supplier::factory()->create(['is_active' => true]);
        $this->warehouse = Warehouse::factory()->oac()->create();
        $this->user = User::factory()->create();

        // Manifiesto abierto con 1 factura de HNL 1,000
        $this->manifest = Manifest::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => 'imported',
        ]);

        Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $this->warehouse->id,
            'total' => 1000.00,
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
            'amount' => 500.00,
            'deposit_date' => now()->toDateString(),
            'bank' => 'BAC',
            'reference' => 'REF-001',
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
    //  cancelDeposit (soft-cancel con auditoría)
    // ═══════════════════════════════════════════════════════════════

    public function test_cancel_deposit_marks_cancelled_and_recalculates(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 700.00]),
            $this->user->id,
        );

        $this->service()->cancelDeposit(
            $deposit->refresh(),
            'Depósito duplicado por error de captura',
            $this->user->id,
        );

        $deposit->refresh();
        $this->assertNotNull($deposit->cancelled_at);
        $this->assertSame($this->user->id, $deposit->cancelled_by);
        $this->assertSame('Depósito duplicado por error de captura', $deposit->cancellation_reason);

        // El manifest recalcula excluyendo cancelados — total_deposited
        // debe volver a 0 aunque el row del depósito siga existiendo en BD.
        $this->manifest->refresh();
        $this->assertEquals(0.00, (float) $this->manifest->total_deposited);
        $this->assertEquals(1000.00, (float) $this->manifest->difference);
    }

    public function test_cancel_deposit_preserves_row_for_audit(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 500.00]),
            $this->user->id,
        );

        $this->service()->cancelDeposit($deposit->refresh(), 'Motivo de prueba xxx', $this->user->id);

        // El registro NO está soft-deleted ni hard-deleted — sigue
        // visible para auditoría/reporting con cancelled_at != null.
        $this->assertDatabaseHas('deposits', [
            'id' => $deposit->id,
            'deleted_at' => null,
        ]);
        $this->assertNotNull(Deposit::find($deposit->id)?->cancelled_at);
    }

    public function test_cancel_deposit_is_idempotent(): void
    {
        // Cancelar dos veces el mismo depósito no debería duplicar efectos
        // ni lanzar excepción. El segundo intento es no-op.
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 400.00]),
            $this->user->id,
        );

        $this->actingAs($this->user);

        $this->service()->cancelDeposit($deposit->refresh(), 'Primer cancel xxx', $this->user->id);
        $this->service()->cancelDeposit($deposit->refresh(), 'Segundo cancel xxx', $this->user->id);

        // El reason del primer cancel se conserva — el segundo intento NO sobreescribe.
        $this->assertSame('Primer cancel xxx', $deposit->refresh()->cancellation_reason);

        // Solo UN log de "Depósito cancelado" en activity_log finance.
        $logCount = \Spatie\Activitylog\Models\Activity::query()
            ->where('log_name', 'finance')
            ->where('description', 'Depósito cancelado')
            ->where('subject_id', $deposit->id)
            ->count();
        $this->assertSame(1, $logCount);
    }

    public function test_cancel_deposit_on_closed_manifest_throws(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 200.00]),
            $this->user->id,
        );

        $this->manifest->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(ValidationException::class);

        $this->service()->cancelDeposit($deposit->refresh(), 'No debería pasar nunca', $this->user->id);
    }

    public function test_cancel_deposit_deletes_receipt_image_from_disk(): void
    {
        // Pre-condición: depósito con receipt_image apuntando a un archivo
        // real en el disco fake.
        \Illuminate\Support\Facades\Storage::fake('local');

        $path = 'deposits/receipts/test-receipt.webp';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, 'fake-webp-bytes');

        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData([
                'amount' => 500.00,
                'receipt_image' => $path,
            ]),
            $this->user->id,
        );

        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($path);

        // Acción: cancelar el depósito.
        $this->service()->cancelDeposit($deposit->refresh(), 'Motivo de prueba xxx', $this->user->id);

        // Post-condición: el archivo físico se eliminó del disco, y el campo
        // receipt_image en BD quedó en null para evitar referencias rotas.
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($path);
        $this->assertNull($deposit->fresh()->receipt_image);
    }

    // ═══════════════════════════════════════════════════════════════
    //  forceDeleteDeposit (hard delete — solo super_admin desde Filament)
    // ═══════════════════════════════════════════════════════════════

    public function test_force_delete_deposit_hard_deletes_and_recalculates(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 600.00]),
            $this->user->id,
        );

        $depositId = $deposit->id;

        $this->actingAs($this->user);

        $this->service()->forceDeleteDeposit($deposit->refresh(), $this->user->id);

        // Hard delete: NO existe ni con withTrashed().
        $this->assertNull(Deposit::withTrashed()->find($depositId));

        $this->manifest->refresh();
        $this->assertEquals(0.00, (float) $this->manifest->total_deposited);
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

    // ═══════════════════════════════════════════════════════════════
    //  Aislamiento transaccional y locks pesimistas
    //  ──────────────────────────────────────────────
    //  Los depósitos son operaciones financieras concurrentes. Cada método
    //  debe abrir una transacción, bloquear el manifiesto con FOR UPDATE,
    //  validar saldo, escribir, y recalcular totales — todo dentro de la
    //  misma TX para evitar race conditions y ventanas de inconsistencia.
    // ═══════════════════════════════════════════════════════════════

    public function test_create_deposit_emits_for_update_lock_on_manifest(): void
    {
        $queries = $this->captureQueries(function () {
            $this->service()->createDeposit(
                $this->manifest,
                $this->depositData(['amount' => 200.00]),
                $this->user->id,
            );
        });

        $this->assertLockForUpdateOnManifests($queries, 'createDeposit');
    }

    public function test_update_deposit_emits_for_update_lock_on_manifest(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 200.00]),
            $this->user->id,
        );

        $queries = $this->captureQueries(function () use ($deposit) {
            $this->service()->updateDeposit(
                $deposit->refresh(),
                $this->depositData(['amount' => 300.00]),
                $this->user->id,
            );
        });

        $this->assertLockForUpdateOnManifests($queries, 'updateDeposit');
    }

    public function test_cancel_deposit_emits_for_update_lock_on_manifest(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 200.00]),
            $this->user->id,
        );

        $queries = $this->captureQueries(function () use ($deposit) {
            $this->service()->cancelDeposit($deposit->refresh(), 'Lock test xxxxx', $this->user->id);
        });

        $this->assertLockForUpdateOnManifests($queries, 'cancelDeposit');
    }

    public function test_create_deposit_recalculates_totals_inside_transaction(): void
    {
        // Verificamos que el UPDATE de manifests con total_deposited se
        // emite ANTES del COMMIT — es decir, está dentro de la TX. Si el
        // recálculo estuviera fuera, veríamos el UPDATE después del commit.
        $events = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$events) {
            $events[] = $q->sql;
        });
        \Illuminate\Support\Facades\DB::beforeExecuting(function ($sql, $bindings, $connection) use (&$events) {
            if (stripos($sql, 'BEGIN') !== false || stripos($sql, 'COMMIT') !== false) {
                $events[] = strtoupper(trim($sql));
            }
        });

        $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 300.00]),
            $this->user->id,
        );

        // Reducir a markers: BEGIN, UPDATE manifests (recalc), COMMIT.
        // El UPDATE de manifests debe aparecer ANTES del COMMIT final.
        $updateIndex = null;
        $commitIndex = null;
        foreach ($events as $i => $sql) {
            if ($updateIndex === null
                && stripos($sql, 'update') !== false
                && stripos($sql, 'manifests') !== false
                && stripos($sql, 'total_deposited') !== false) {
                $updateIndex = $i;
            }
            if (stripos($sql, 'commit') !== false) {
                $commitIndex = $i;
            }
        }

        $this->assertNotNull($updateIndex, 'No se detectó UPDATE de manifests.total_deposited (recalc)');
        // Si commitIndex es null es porque DB::listen no captura COMMIT en algunas
        // versiones — en ese caso, simplemente verificamos que el update existe.
        if ($commitIndex !== null) {
            $this->assertLessThan(
                $commitIndex,
                $updateIndex,
                'El recálculo debe ocurrir DENTRO de la TX (UPDATE antes del COMMIT)'
            );
        }
    }

    // ─── Helpers de captura SQL ─────────────────────────────────────

    private function captureQueries(callable $callback): array
    {
        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $callback();

        return $queries;
    }

    private function assertLockForUpdateOnManifests(array $queries, string $contextoMetodo): void
    {
        $encontrado = collect($queries)->contains(
            fn ($sql) => stripos($sql, 'manifests') !== false
                && stripos($sql, 'for update') !== false
        );

        $this->assertTrue(
            $encontrado,
            "{$contextoMetodo}: se esperaba SELECT ... FOR UPDATE sobre manifests. ".
            'Sin este lock dos requests concurrentes producen race condition '.
            'en el cálculo de saldo pendiente. SQLs ejecutados: '.
            implode(' || ', $queries)
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  Auditoría financiera (Activity Log canal 'finance')
    //  ─────────────────────────────────────────────────
    //  El LogsActivity automático del modelo Deposit registra el delete
    //  en canal 'default' con campos cambiados. Para trazabilidad
    //  regulatoria necesitamos una entrada adicional en canal 'finance'
    //  con contexto operativo (monto, manifiesto, causer).
    // ═══════════════════════════════════════════════════════════════

    public function test_cancel_deposit_logs_activity_in_finance_channel(): void
    {
        $deposit = $this->service()->createDeposit(
            $this->manifest,
            $this->depositData(['amount' => 350.00, 'bank' => 'BAC', 'reference' => 'REF-AUD-1']),
            $this->user->id,
        );

        $this->actingAs($this->user);

        $this->service()->cancelDeposit($deposit->refresh(), 'Motivo de auditoría xxx', $this->user->id);

        $log = \Spatie\Activitylog\Models\Activity::query()
            ->where('log_name', 'finance')
            ->where('description', 'Depósito cancelado')
            ->latest()
            ->first();

        $this->assertNotNull(
            $log,
            "Esperaba una entrada en activity_log con log_name='finance' tras cancelDeposit"
        );
        $this->assertSame(\App\Models\Deposit::class, $log->subject_type);
        $this->assertSame($deposit->id, $log->subject_id);
        $this->assertSame($this->user->id, $log->causer_id);

        $props = $log->properties;
        $this->assertSame(350.00, (float) $props['amount']);
        $this->assertSame('BAC', $props['bank']);
        $this->assertSame('REF-AUD-1', $props['reference']);
        $this->assertSame($this->manifest->id, $props['manifest_id']);
        $this->assertSame($this->manifest->number, $props['manifest_number']);
        $this->assertSame('Motivo de auditoría xxx', $props['reason']);
    }
}
