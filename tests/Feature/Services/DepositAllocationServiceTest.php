<?php

namespace Tests\Feature\Services;

use App\Models\Deposit;
use App\Models\DepositAllocation;
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
 * Aplicación de un depósito bancario entre varios manifiestos.
 *
 * El caso real que cubre: el encargado no quiere hacer dos transferencias,
 * así que manda UNA boleta que cubre el manifiesto de hoy y de paso tapa los
 * centavos que faltaban en uno anterior.
 *
 * La invariante que se protege en casi todos los tests es siempre la misma:
 *
 *     SUM(deposit_allocations.amount) == deposits.amount
 *
 * Si se rompe, hay dinero registrado que no está aplicado a ningún manifiesto
 * — es decir, plata en el banco que el sistema no sabe explicar.
 */
class DepositAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Warehouse $oac;

    private Warehouse $oas;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->supplier = Supplier::factory()->create(['is_active' => true]);
        $this->oac = Warehouse::factory()->oac()->create();
        $this->oas = Warehouse::factory()->oas()->create();
        $this->user = User::factory()->create();
    }

    private function service(): DepositService
    {
        return $this->app->make(DepositService::class);
    }

    /**
     * Manifiesto con una factura del monto indicado, ya recalculado.
     * `$daysAgo` controla el orden FIFO del reparto.
     */
    private function manifest(float $total, int $daysAgo = 0, ?Warehouse $warehouse = null): Manifest
    {
        $manifest = Manifest::factory()->create([
            'supplier_id' => $this->supplier->id,
            'status' => 'imported',
            'date' => now()->subDays($daysAgo)->toDateString(),
        ]);

        Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => ($warehouse ?? $this->oac)->id,
            'total' => $total,
        ]);

        $manifest->recalculateTotals();

        return $manifest->refresh();
    }

    /**
     * Manifiesto viejo al que le queda un saldo pendiente EXACTO.
     *
     * El barrido automático solo mira deudas chicas y viejas, así que casi
     * todos los tests necesitan construir ese estado: se factura el total y
     * se deposita todo menos el pendiente buscado.
     */
    private function manifestConPendiente(float $total, float $pendiente, int $daysAgo, ?Warehouse $warehouse = null): Manifest
    {
        $manifest = $this->manifest($total, $daysAgo, $warehouse);
        $aDepositar = round($total - $pendiente, 2);

        if ($aDepositar > 0) {
            $this->service()->createDeposit(
                $manifest,
                $this->depositData(['amount' => $aDepositar]),
                $this->user->id,
            );
        }

        return $manifest->refresh();
    }

    private function depositData(array $overrides = []): array
    {
        return array_merge([
            'amount' => 100.00,
            'deposit_date' => now()->toDateString(),
            'bank' => 'BAC',
            'reference' => 'REF-'.fake()->unique()->numerify('#####'),
            'observations' => null,
        ], $overrides);
    }

    private function assertInvariant(Deposit $deposit): void
    {
        $this->assertEquals(
            round((float) $deposit->amount, 2),
            round((float) $deposit->allocations()->sum('amount'), 2),
            'Se rompió la invariante SUM(allocations) == deposit.amount'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    //  Reparto básico
    // ═══════════════════════════════════════════════════════════════

    public function test_deposit_within_pending_creates_single_allocation(): void
    {
        $manifest = $this->manifest(1000.00);

        $deposit = $this->service()->createDeposit(
            $manifest,
            $this->depositData(['amount' => 400.00]),
            $this->user->id,
        );

        $this->assertCount(1, $deposit->allocations);
        $this->assertEquals(400.00, (float) $deposit->allocations->first()->amount);
        $this->assertSame($manifest->id, $deposit->allocations->first()->manifest_id);
        $this->assertInvariant($deposit);
    }

    /**
     * El caso central del requerimiento: una boleta que cubre el manifiesto
     * de hoy y tapa los centavos del anterior.
     */
    public function test_excess_covers_older_manifest_pending_cents(): void
    {
        $viejo = $this->manifest(1000.00, daysAgo: 20);

        // Al viejo le faltan 0.32 (depositaron 999.68 en su momento).
        $this->service()->createDeposit($viejo, $this->depositData(['amount' => 999.68]), $this->user->id);
        $viejo->refresh();
        $this->assertEquals(0.32, (float) $viejo->difference);

        $hoy = $this->manifest(500.00, daysAgo: 0);

        // Una sola transferencia por 500.32: cubre hoy y tapa los 0.32 viejos.
        $deposit = $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 500.32,
                'justification' => 'Transferencia única: cubre el manifiesto de hoy y el saldo del anterior.',
            ]),
            $this->user->id,
        );

        $this->assertInvariant($deposit);
        $this->assertCount(2, $deposit->allocations);

        $hoy->refresh();
        $viejo->refresh();

        $this->assertEquals(0.00, (float) $hoy->difference, 'El manifiesto de hoy debe quedar cuadrado');
        $this->assertEquals(0.00, (float) $viejo->difference, 'El viejo debe quedar cuadrado por el excedente');
        $this->assertTrue($viejo->isReadyToClose(), 'Tras cubrir los centavos, el viejo ya se puede cerrar');
    }

    public function test_excess_is_distributed_oldest_first(): void
    {
        $masViejo = $this->manifestConPendiente(100.00, pendiente: 5.00, daysAgo: 30);
        $medio = $this->manifestConPendiente(100.00, pendiente: 3.00, daysAgo: 20);
        $hoy = $this->manifest(50.00, daysAgo: 0);

        // 50 (hoy) + 5 (más viejo) + 3 (medio) = 58
        $deposit = $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 58.00,
                'justification' => 'Transferencia única para ponerse al día con los manifiestos pendientes.',
            ]),
            $this->user->id,
        );

        $this->assertInvariant($deposit);

        $porManifiesto = $deposit->allocations->pluck('amount', 'manifest_id')
            ->map(fn ($a) => (float) $a);

        $this->assertEquals(50.00, $porManifiesto[$hoy->id]);
        $this->assertEquals(5.00, $porManifiesto[$masViejo->id], 'El más antiguo se cubre primero');
        $this->assertEquals(3.00, $porManifiesto[$medio->id], 'Después el siguiente en antigüedad');
    }

    /**
     * El barrido NO toca manifiestos recientes aunque tengan saldo chico.
     *
     * Un manifiesto de pocos días sigue en la conciliación del encargado:
     * verlo pagado solo, con plata que él mandó para otra cosa, le desordena
     * el trabajo y le hace desconfiar de los números.
     */
    public function test_excess_ignores_manifests_newer_than_the_minimum_age(): void
    {
        $reciente = $this->manifestConPendiente(100.00, pendiente: 5.00, daysAgo: 3);
        $hoy = $this->manifest(100.00, daysAgo: 0);

        $deposit = $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 150.00,
                'justification' => 'Sobredepósito intencional para probar el umbral de antigüedad.',
            ]),
            $this->user->id,
        );

        $this->assertInvariant($deposit);
        $reciente->refresh();
        $hoy->refresh();

        $this->assertEquals(5.00, (float) $reciente->difference, 'Un manifiesto reciente no debe recibir excedente');
        $this->assertEquals(-50.00, (float) $hoy->difference, 'Todo el excedente se queda como sobrepago del origen');
    }

    /**
     * Una deuda grande no es un redondeo: se deposita explícitamente, no se
     * tapa con el sobrante de otra boleta.
     */
    public function test_excess_ignores_debts_larger_than_the_cap(): void
    {
        $viejoConDeudaGrande = $this->manifestConPendiente(100.00, pendiente: 50.00, daysAgo: 30);
        $hoy = $this->manifest(100.00, daysAgo: 0);

        $deposit = $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 150.00,
                'justification' => 'Sobredepósito intencional para probar el tope del barrido.',
            ]),
            $this->user->id,
        );

        $this->assertInvariant($deposit);
        $viejoConDeudaGrande->refresh();

        $this->assertEquals(50.00, (float) $viejoConDeudaGrande->difference, 'Una deuda mayor al tope no se barre');
        $this->assertEquals(-50.00, (float) $hoy->refresh()->difference);
    }

    public function test_sweep_thresholds_are_configurable(): void
    {
        config(['manifests.reparto.tope_pendiente_hnl' => 100.00]);

        $viejo = $this->manifestConPendiente(100.00, pendiente: 50.00, daysAgo: 30);
        $hoy = $this->manifest(100.00, daysAgo: 0);

        $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 150.00,
                'justification' => 'Con el tope elevado, esta deuda sí entra al barrido.',
            ]),
            $this->user->id,
        );

        $this->assertEquals(0.00, (float) $viejo->refresh()->difference);
        $this->assertEquals(0.00, (float) $hoy->refresh()->difference);
    }

    /**
     * Si sobra dinero después de llenar TODOS los candidatos, el remanente
     * vuelve al manifiesto de origen. Es el único escenario que deja un
     * manifiesto sobre-depositado — y por eso exige justificación.
     */
    public function test_leftover_beyond_all_candidates_lands_on_origin(): void
    {
        $hoy = $this->manifest(100.00);

        $deposit = $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 250.00,
                'justification' => 'Depósito adelantado, se aplicará al manifiesto que viene mañana.',
            ]),
            $this->user->id,
        );

        $this->assertInvariant($deposit);
        $this->assertCount(1, $deposit->allocations);

        $hoy->refresh();
        $this->assertEquals(-150.00, (float) $hoy->difference);
        $this->assertTrue($hoy->isOverpaid());

        // El sobrepago quedó justificado al registrar la boleta, así que el
        // manifiesto se puede cerrar dejando constancia del exceso. Sobrar no
        // bloquea el cierre; faltar sí.
        $this->assertTrue($hoy->isReadyToClose(), 'Un sobrepago justificado no debe trabar el cierre');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Límites del reparto
    // ═══════════════════════════════════════════════════════════════

    public function test_excess_never_reaches_a_manifest_of_another_warehouse(): void
    {
        // Deuda chica y vieja: cumpliría todas las condiciones del barrido
        // SALVO la bodega. Así el test aísla exactamente esa regla.
        $otraBodega = $this->manifestConPendiente(100.00, pendiente: 5.00, daysAgo: 25, warehouse: $this->oas);
        $hoy = $this->manifest(100.00, daysAgo: 0, warehouse: $this->oac);

        $deposit = $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 300.00,
                'justification' => 'Prueba de aislamiento entre bodegas distintas.',
            ]),
            $this->user->id,
        );

        $this->assertInvariant($deposit);
        $otraBodega->refresh();

        $this->assertEquals(5.00, (float) $otraBodega->difference, 'La bodega OAS no debe recibir dinero de OAC');

        // Su saldo depositado es el que ya traía de su propio depósito; lo que
        // se verifica es que ESTA boleta no le aplicó nada.
        $this->assertNotContains(
            $otraBodega->id,
            $deposit->allocations->pluck('manifest_id')->all(),
            'La boleta de OAC no debe tener ninguna línea hacia un manifiesto de OAS'
        );
    }

    public function test_excess_never_reaches_a_closed_manifest(): void
    {
        $cerrado = $this->manifestConPendiente(100.00, pendiente: 5.00, daysAgo: 25);
        $cerrado->update(['status' => 'closed', 'closed_at' => now()]);

        $hoy = $this->manifest(100.00);

        $deposit = $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 300.00,
                'justification' => 'Prueba de que un manifiesto cerrado queda fuera del reparto.',
            ]),
            $this->user->id,
        );

        $this->assertInvariant($deposit);
        $this->assertEquals(5.00, (float) $cerrado->refresh()->difference, 'Un manifiesto cerrado no recibe dinero');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Totales del manifiesto
    // ═══════════════════════════════════════════════════════════════

    /**
     * total_deposited se calcula desde las allocations, no desde
     * deposits.manifest_id. Sin esto, el dinero que llega como excedente de
     * otra boleta no se vería reflejado en el manifiesto que lo recibió.
     */
    public function test_total_deposited_counts_money_received_from_a_foreign_deposit(): void
    {
        $viejo = $this->manifest(1000.00, daysAgo: 20);
        $this->service()->createDeposit($viejo, $this->depositData(['amount' => 999.68]), $this->user->id);

        $hoy = $this->manifest(500.00);
        $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 500.32,
                'justification' => 'Transferencia única que cubre ambos manifiestos.',
            ]),
            $this->user->id,
        );

        $viejo->refresh();

        $this->assertEquals(1000.00, (float) $viejo->total_deposited);
        // El depósito que aportó los 0.32 NO pertenece a este manifiesto…
        $this->assertEquals(999.68, (float) $viejo->deposits()->sum('amount'));
        // …pero su dinero sí está aplicado acá.
        $this->assertEquals(1000.00, DepositAllocation::totalForManifest($viejo->id));
    }

    // ═══════════════════════════════════════════════════════════════
    //  Cancelación y edición
    // ═══════════════════════════════════════════════════════════════

    public function test_cancelling_a_split_deposit_recalculates_every_affected_manifest(): void
    {
        $viejo = $this->manifest(1000.00, daysAgo: 20);
        $this->service()->createDeposit($viejo, $this->depositData(['amount' => 999.68]), $this->user->id);

        $hoy = $this->manifest(500.00);
        $deposit = $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 500.32,
                'justification' => 'Transferencia única que cubre ambos manifiestos.',
            ]),
            $this->user->id,
        );

        $this->service()->cancelDeposit($deposit, 'Boleta equivocada', $this->user->id);

        $hoy->refresh();
        $viejo->refresh();

        $this->assertEquals(500.00, (float) $hoy->difference, 'El origen vuelve a deber todo');
        $this->assertEquals(0.32, (float) $viejo->difference, 'El viejo recupera su faltante');

        // Las allocations NO se borran: quedan para poder restaurar el
        // depósito sin backfill. Lo que cambia es que dejan de contar.
        $this->assertCount(2, $deposit->allocations()->get());
    }

    public function test_updating_the_amount_redistributes_the_whole_split(): void
    {
        $viejo = $this->manifest(1000.00, daysAgo: 20);
        $this->service()->createDeposit($viejo, $this->depositData(['amount' => 999.68]), $this->user->id);

        $hoy = $this->manifest(500.00);
        $deposit = $this->service()->createDeposit(
            $hoy,
            $this->depositData([
                'amount' => 500.32,
                'justification' => 'Transferencia única que cubre ambos manifiestos.',
            ]),
            $this->user->id,
        );

        // Corrección: la boleta era de 500.00, no cubría los centavos viejos.
        $this->service()->updateDeposit(
            $deposit,
            $this->depositData(['amount' => 500.00, 'reference' => $deposit->reference]),
            $this->user->id,
        );

        $deposit->refresh();
        $hoy->refresh();
        $viejo->refresh();

        $this->assertInvariant($deposit);
        $this->assertEquals(0.00, (float) $hoy->difference);
        $this->assertEquals(0.32, (float) $viejo->difference, 'El viejo vuelve a quedar con su faltante');
        $this->assertCount(1, $deposit->allocations()->get());
    }

    // ═══════════════════════════════════════════════════════════════
    //  Justificación
    // ═══════════════════════════════════════════════════════════════

    public function test_justification_shorter_than_15_chars_is_rejected(): void
    {
        $manifest = $this->manifest(100.00);

        $this->expectException(ValidationException::class);

        $this->service()->createDeposit(
            $manifest,
            $this->depositData(['amount' => 150.00, 'justification' => 'porque si']),
            $this->user->id,
        );
    }

    public function test_justification_is_persisted_for_audit(): void
    {
        $manifest = $this->manifest(100.00);
        $motivo = 'Una sola transferencia por pedido del gerente de bodega.';

        $deposit = $this->service()->createDeposit(
            $manifest,
            $this->depositData(['amount' => 150.00, 'justification' => $motivo]),
            $this->user->id,
        );

        $this->assertSame($motivo, $deposit->fresh()->justification);
    }
}
