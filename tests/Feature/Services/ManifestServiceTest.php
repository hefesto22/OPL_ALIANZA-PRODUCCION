<?php

namespace Tests\Feature\Services;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Feature tests para ManifestService — ciclo de vida de cierre/reapertura.
 *
 * Los métodos de cierre y reapertura vivían en el modelo (Manifest::close/
 * Manifest::reopen) sin validaciones server-side. La UI de Filament filtraba
 * con isReadyToClose() antes de mostrar el botón, pero un request directo
 * (API, CLI, o lógica futura) podía cerrar un manifiesto con diferencia
 * !== 0 o devoluciones pendientes — corrompiendo cifras.
 *
 * Este Service centraliza la lógica y agrega los guards. Los tests verifican
 * tanto el happy path como cada pre-condición rechazada.
 */
class ManifestServiceTest extends TestCase
{
    use RefreshDatabase;

    private ManifestService $service;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ManifestService::class);
        $this->warehouse = Warehouse::factory()->oac()->create();
    }

    private function balancedManifest(array $overrides = []): Manifest
    {
        return Manifest::factory()->create(array_merge([
            'warehouse_id' => $this->warehouse->id,
            'status' => 'imported',
            'total_invoices' => 1000,
            'total_returns' => 0,
            'total_to_deposit' => 1000,
            'total_deposited' => 1000,
            'difference' => 0,
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════════════
    //  closeManifest
    // ═══════════════════════════════════════════════════════════════

    public function test_close_manifest_sets_status_user_and_timestamp(): void
    {
        $manifest = $this->balancedManifest();
        $user = User::factory()->create();

        $this->service->closeManifest($manifest, $user->id);

        $manifest->refresh();
        $this->assertSame('closed', $manifest->status);
        $this->assertSame($user->id, $manifest->closed_by);
        $this->assertNotNull($manifest->closed_at);
    }

    public function test_close_rejects_when_manifest_already_closed(): void
    {
        $manifest = $this->balancedManifest(['status' => 'closed', 'closed_at' => now()]);
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya está cerrado/');

        $this->service->closeManifest($manifest, $user->id);
    }

    public function test_close_rejects_when_money_is_missing(): void
    {
        $manifest = $this->balancedManifest([
            'total_deposited' => 500,
            'difference' => 500,
        ]);
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/faltan/i');

        $this->service->closeManifest($manifest, $user->id);
    }

    /**
     * Sobrar NO es lo mismo que faltar.
     *
     * Con la regla vieja (diferencia == 0 exacta) un manifiesto sobrepagado
     * quedaba abierto para siempre aunque estuviera cobrado de sobra, y se
     * mezclaba en Activos con los que sí necesitan atención. Se cierra
     * siempre que exista la justificación del depósito que generó el exceso.
     */
    public function test_close_allows_an_overpaid_manifest_with_justification(): void
    {
        $manifest = $this->balancedManifest([
            'total_deposited' => 1050,
            'difference' => -50,
        ]);

        Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'amount' => 1050,
            'justification' => 'Transferencia redondeada por pedido del encargado de bodega.',
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->service->closeManifest($manifest->fresh(), $user->id);

        $this->assertSame('closed', $manifest->fresh()->status);
    }

    public function test_close_rejects_an_overpaid_manifest_without_justification(): void
    {
        $manifest = $this->balancedManifest([
            'total_deposited' => 1050,
            'difference' => -50,
        ]);

        // Depósito sin justificación: solo puede venir de un dato heredado o
        // de una carga manual, porque DepositService la exige al registrar.
        Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'amount' => 1050,
            'justification' => null,
        ]);

        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sobre-depositado/i');

        $this->service->closeManifest($manifest->fresh(), $user->id);
    }

    public function test_closing_with_overpayment_is_logged_in_the_finance_channel(): void
    {
        $manifest = $this->balancedManifest([
            'total_deposited' => 1050,
            'difference' => -50,
        ]);

        Deposit::factory()->create([
            'manifest_id' => $manifest->id,
            'amount' => 1050,
            'justification' => 'Transferencia redondeada por pedido del encargado de bodega.',
        ]);

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->service->closeManifest($manifest->fresh(), $user->id);

        $activity = \Spatie\Activitylog\Models\Activity::query()
            ->where('log_name', 'finance')
            ->where('description', 'Manifiesto cerrado con sobrepago')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity, 'Cerrar con plata de más debe quedar registrado');
        $this->assertEquals(50.00, (float) $activity->properties['sobrepago']);
    }

    public function test_close_rejects_when_no_total_to_deposit(): void
    {
        $manifest = $this->balancedManifest([
            'total_invoices' => 0,
            'total_to_deposit' => 0,
            'total_deposited' => 0,
            'difference' => 0,
        ]);
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no tiene importes/');

        $this->service->closeManifest($manifest, $user->id);
    }

    public function test_close_rejects_when_pending_returns_exist(): void
    {
        $manifest = $this->balancedManifest();
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->warehouse->id,
        ]);

        InvoiceReturn::factory()->create([
            'manifest_id' => $manifest->id,
            'invoice_id' => $invoice->id,
            'return_reason_id' => ReturnReason::factory(),
            'warehouse_id' => $this->warehouse->id,
            'status' => 'pending',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/pendientes/');

        $this->service->closeManifest($manifest, $user->id);
    }

    // ═══════════════════════════════════════════════════════════════
    //  reopenManifest
    // ═══════════════════════════════════════════════════════════════

    public function test_reopen_manifest_clears_closed_metadata(): void
    {
        $user = User::factory()->create();
        $manifest = Manifest::factory()->closed()->create([
            'warehouse_id' => $this->warehouse->id,
            'closed_by' => $user->id,
        ]);

        $this->service->reopenManifest($manifest);

        $manifest->refresh();
        $this->assertSame('imported', $manifest->status);
        $this->assertNull($manifest->closed_by);
        $this->assertNull($manifest->closed_at);
    }

    public function test_reopen_rejects_when_manifest_is_not_closed(): void
    {
        $manifest = $this->balancedManifest(['status' => 'imported']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no está cerrado/');

        $this->service->reopenManifest($manifest);
    }
}
