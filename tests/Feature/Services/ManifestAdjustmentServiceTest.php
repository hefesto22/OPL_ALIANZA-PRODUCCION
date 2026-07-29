<?php

namespace Tests\Feature\Services;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DepositService;
use App\Services\ManifestAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ajuste de diferencia por centavos.
 *
 * El escenario que motivó esta funcionalidad: en producción había manifiestos
 * con difference de -0.01 (el sistema los generaba por un margen de tolerancia
 * en el validador de depósitos). Como isReadyToClose() exige cero exacto,
 * quedaban imposibles de cerrar y ningún depósito futuro podía arreglarlos:
 * no hay plata que mover cuando ya sobra.
 *
 * El ajuste es la única vía para llevarlos a cero, y deja firma de quién lo
 * autorizó. isReadyToClose() no se relajó.
 */
class ManifestAdjustmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Manifest $manifest;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);

        $supplier = Supplier::factory()->create(['is_active' => true]);
        $warehouse = Warehouse::factory()->oac()->create();
        $this->user = User::factory()->create();

        $this->manifest = Manifest::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => 'imported',
        ]);

        Invoice::factory()->create([
            'manifest_id' => $this->manifest->id,
            'warehouse_id' => $warehouse->id,
            'total' => 1000.00,
        ]);

        $this->manifest->recalculateTotals();
        $this->manifest->refresh();
    }

    private function service(): ManifestAdjustmentService
    {
        return $this->app->make(ManifestAdjustmentService::class);
    }

    private function deposit(float $amount, ?string $justification = null): void
    {
        $this->app->make(DepositService::class)->createDeposit(
            $this->manifest,
            array_filter([
                'amount' => $amount,
                'deposit_date' => now()->toDateString(),
                'bank' => 'BAC',
                'reference' => 'REF-'.fake()->unique()->numerify('#####'),
                'justification' => $justification,
            ]),
            $this->user->id,
        );

        $this->manifest->refresh();
    }

    /**
     * Escribe un depósito sobre-depositado SIN justificación, directo en la
     * base.
     *
     * No se puede usar DepositService: exige justificación en cuanto el monto
     * supera el pendiente, justamente para que este estado no vuelva a
     * generarse. Es la reproducción de un dato heredado.
     */
    private function seedLegacyOverDeposit(float $amount): void
    {
        Deposit::create([
            'manifest_id' => $this->manifest->id,
            'amount' => $amount,
            'deposit_date' => now()->toDateString(),
            'bank' => 'BAC',
            'reference' => 'LEGACY-'.fake()->unique()->numerify('#####'),
            'created_by' => $this->user->id,
        ]);

        $this->manifest->recalculateTotals();
        $this->manifest->refresh();
    }

    // ═══════════════════════════════════════════════════════════════
    //  Los dos casos reales de producción
    // ═══════════════════════════════════════════════════════════════

    public function test_adjustment_closes_a_manifest_short_by_cents(): void
    {
        $this->deposit(999.68);
        $this->assertEquals(0.32, (float) $this->manifest->difference);
        $this->assertFalse($this->manifest->isReadyToClose());

        $this->service()->adjust($this->manifest, 0.32, 'Redondeo bancario verificado contra estado de cuenta.', $this->user->id);

        $this->manifest->refresh();

        $this->assertEquals(0.00, (float) $this->manifest->difference);
        $this->assertEquals(0.32, (float) $this->manifest->adjustment_amount);
        $this->assertTrue($this->manifest->isReadyToClose());
    }

    /**
     * El caso REAL de producción: un sobrepago HEREDADO, sin justificación.
     *
     * Los 4 manifiestos con −0.01 que hay en producción los generó el margen
     * de tolerancia de +0.01 que tenía el validador viejo: dejaba pasar el
     * centavo sin pedir explicación y sin registrarla. Como no hay
     * justificación, el cierre está bloqueado y el reparto no puede ayudar
     * (no hay plata que mover: ya sobra). El ajuste es la única salida.
     */
    public function test_adjustment_closes_a_legacy_over_deposited_manifest(): void
    {
        $this->seedLegacyOverDeposit(1000.01);

        $this->assertEquals(-0.01, (float) $this->manifest->difference);
        $this->assertFalse(
            $this->manifest->isReadyToClose(),
            'Sin justificación, el sobrepago sí bloquea el cierre'
        );

        $this->service()->adjust($this->manifest, -0.01, 'Centavo de más del banco, se da por bueno.', $this->user->id);

        $this->manifest->refresh();

        $this->assertEquals(0.00, (float) $this->manifest->difference);
        $this->assertTrue($this->manifest->isReadyToClose());
    }

    /**
     * La contracara: un sobrepago registrado HOY ya nace con justificación
     * obligatoria, así que se cierra solo y no necesita ningún ajuste.
     *
     * Se deja cubierto para que quede claro el alcance real de esta función:
     * el ajuste existe para limpiar la deuda heredada, no como muleta del
     * flujo normal.
     */
    public function test_a_justified_overpayment_needs_no_adjustment(): void
    {
        $this->deposit(1000.01, 'Depósito con un centavo de más por redondeo del banco emisor.');

        $this->assertEquals(-0.01, (float) $this->manifest->difference);
        $this->assertTrue($this->manifest->isReadyToClose());
    }

    /**
     * La columna "Depositado" debe seguir diciendo cuánta plata entró al
     * banco. Si el ajuste la inflara, el sistema mentiría sobre el efectivo.
     */
    public function test_adjustment_does_not_inflate_total_deposited(): void
    {
        $this->deposit(999.68);
        $this->service()->adjust($this->manifest, 0.32, 'Redondeo bancario verificado.', $this->user->id);

        $this->manifest->refresh();

        $this->assertEquals(999.68, (float) $this->manifest->total_deposited);
        $this->assertEquals(0.32, (float) $this->manifest->adjustment_amount);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Guardas
    // ═══════════════════════════════════════════════════════════════

    public function test_adjustment_over_the_configured_cap_is_rejected(): void
    {
        config(['manifests.ajustes.tope_hnl' => 1.00]);

        $this->expectException(ValidationException::class);

        $this->service()->adjust($this->manifest, 25.00, 'Intento de cuadrar a mano una diferencia grande.', $this->user->id);
    }

    public function test_cap_is_configurable_without_touching_code(): void
    {
        config(['manifests.ajustes.tope_hnl' => 50.00]);

        $this->deposit(950.00);
        $adjustment = $this->service()->adjust($this->manifest, 50.00, 'Tope elevado por decisión administrativa.', $this->user->id);

        $this->assertEquals(50.00, (float) $adjustment->amount);
    }

    public function test_adjustment_without_a_real_reason_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->adjust($this->manifest, 0.01, 'ok', $this->user->id);
    }

    public function test_zero_adjustment_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->adjust($this->manifest, 0.00, 'Motivo suficientemente largo para pasar.', $this->user->id);
    }

    public function test_closed_manifest_does_not_accept_adjustments(): void
    {
        $this->manifest->update(['status' => 'closed', 'closed_at' => now()]);

        $this->expectException(ValidationException::class);

        $this->service()->adjust($this->manifest->refresh(), 0.01, 'Motivo suficientemente largo para pasar.', $this->user->id);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Auditoría
    // ═══════════════════════════════════════════════════════════════

    public function test_adjustment_is_logged_in_the_finance_channel(): void
    {
        $this->actingAs($this->user);
        $this->deposit(999.68);

        $this->service()->adjust($this->manifest, 0.32, 'Redondeo bancario verificado contra estado de cuenta.', $this->user->id);

        $activity = \Spatie\Activitylog\Models\Activity::query()
            ->where('log_name', 'finance')
            ->where('description', 'Ajuste de diferencia registrado')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'El ajuste debe quedar registrado en el canal finance');
        $this->assertSame($this->user->id, $activity->causer_id);
        $this->assertEquals(0.32, (float) $activity->properties['monto_ajuste']);
        $this->assertEquals(0.32, (float) $activity->properties['diferencia_antes']);
        $this->assertEquals(0.00, (float) $activity->properties['diferencia_despues']);
    }

    public function test_adjustment_keeps_the_author_for_traceability(): void
    {
        $adjustment = $this->service()->adjust(
            $this->manifest,
            0.01,
            'Motivo suficientemente largo para pasar la validación.',
            $this->user->id,
        );

        $this->assertSame($this->user->id, $adjustment->created_by);
        $this->assertDatabaseHas('manifest_adjustments', [
            'manifest_id' => $this->manifest->id,
            'created_by' => $this->user->id,
        ]);
    }
}
