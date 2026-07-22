<?php

namespace Tests\Feature\Services;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ReturnService;
use App\Support\BusinessDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Ventana de registro de devoluciones (regla operativa 2026-07-21):
 * N días hábiles (lun–sáb) desde la llegada del manifiesto; al cierre
 * (día N, 11:59 pm) el paquete se publica a Jaremar y queda CONGELADO —
 * crear, editar y cancelar quedan bloqueados para TODOS los roles.
 */
class ReturnServiceVentanaDevolucionesTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private ReturnReason $reason;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Esta suite SÍ prueba la ventana: activar la regla real (5 días
        // hábiles). El TestCase base la desactiva para el resto de suites
        // porque las factories crean manifiestos con fechas del pasado.
        config(['api.devoluciones_ventana_dias_habiles' => 5]);

        $this->warehouse = Warehouse::factory()->create(['code' => 'OAC', 'name' => 'Copán']);
        $this->reason = ReturnReason::factory()->create();
        $this->user = User::factory()->create();
    }

    private function service(): ReturnService
    {
        return $this->app->make(ReturnService::class);
    }

    /**
     * Manifiesto + factura + línea listos para devolver.
     *
     * @return array{0: Manifest, 1: Invoice, 2: InvoiceLine}
     */
    private function makeManifestConFactura(string $manifestDate): array
    {
        $manifest = Manifest::factory()->create([
            'date' => $manifestDate,
            'status' => 'imported',
            'warehouse_id' => $this->warehouse->id,
        ]);

        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->warehouse->id,
        ]);

        $line = InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'quantity_fractions' => 10,
            'quantity_box' => 0,
            'conversion_factor' => 1,
        ]);

        return [$manifest, $invoice, $line];
    }

    private function createReturnData(Invoice $invoice, InvoiceLine $line): array
    {
        return [
            'invoice_id' => $invoice->id,
            'return_reason_id' => $this->reason->id,
            'return_date' => now()->toDateString(),
            'created_by' => $this->user->id,
            'lines' => [[
                'invoice_line_id' => $line->id,
                'quantity_box' => 0,
                'quantity' => 2,
                'line_number' => $line->line_number,
                'product_id' => $line->product_id,
                'product_description' => $line->product_description,
            ]],
        ];
    }

    // ═══════════════════════════════════════════════════════════════

    public function test_deadline_se_fija_automaticamente_al_crear_manifiesto(): void
    {
        // 2026-07-24 es viernes → cierre miércoles 2026-07-29 a las 11:59 pm.
        $manifest = Manifest::factory()->create([
            'date' => '2026-07-24',
            'warehouse_id' => $this->warehouse->id,
        ]);

        $this->assertNotNull($manifest->returns_deadline_at);
        $this->assertSame(
            '2026-07-29 23:59:59',
            $manifest->returnsDeadline()->format('Y-m-d H:i:s'),
        );
    }

    public function test_ventana_abierta_permite_registrar_devolucion(): void
    {
        [, $invoice, $line] = $this->makeManifestConFactura(now()->toDateString());

        $return = $this->service()->createReturn($this->createReturnData($invoice, $line));

        $this->assertSame('approved', $return->status);
        $this->assertSame(1, InvoiceReturn::count());
    }

    public function test_ventana_cerrada_bloquea_registrar_devolucion(): void
    {
        // Manifiesto que llegó hace 15 días: la ventana hábil (5 días)
        // cerró hace rato — el candado aplica a TODOS los roles.
        [, $invoice, $line] = $this->makeManifestConFactura(now()->subDays(15)->toDateString());

        try {
            $this->service()->createReturn($this->createReturnData($invoice, $line));
            $this->fail('Se esperaba ValidationException por ventana cerrada.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ventana', strtolower(collect($e->errors())->flatten()->implode(' ')));
        }

        $this->assertSame(0, InvoiceReturn::count());
    }

    public function test_ventana_cerrada_bloquea_cancelar_devolucion(): void
    {
        // Registrar con la ventana abierta…
        [$manifest, $invoice, $line] = $this->makeManifestConFactura(now()->toDateString());
        $return = $this->service()->createReturn($this->createReturnData($invoice, $line));

        // …y luego cerrar la ventana (simula el paso del tiempo). Update
        // directo a la columna: el hook saving() no recalcula porque la
        // fecha operativa no cambió.
        DB::table('manifests')
            ->where('id', $manifest->id)
            ->update(['returns_deadline_at' => now()->subMinute()]);

        $fresh = InvoiceReturn::findOrFail($return->id);

        $this->expectException(ValidationException::class);
        $this->service()->cancelReturn($fresh, 'intento tardío');
    }

    public function test_ventana_cerrada_bloquea_editar_devolucion(): void
    {
        [$manifest, $invoice, $line] = $this->makeManifestConFactura(now()->toDateString());
        $return = $this->service()->createReturn($this->createReturnData($invoice, $line));

        DB::table('manifests')
            ->where('id', $manifest->id)
            ->update(['returns_deadline_at' => now()->subMinute()]);

        $fresh = InvoiceReturn::findOrFail($return->id);

        $this->expectException(ValidationException::class);
        $this->service()->updateReturn($fresh, [
            'return_reason_id' => $this->reason->id,
            'return_date' => now()->toDateString(),
            'lines' => [[
                'invoice_line_id' => $line->id,
                'quantity_box' => 0,
                'quantity' => 1,
                'line_number' => $line->line_number,
                'product_id' => $line->product_id,
                'product_description' => $line->product_description,
            ]],
        ]);
    }

    public function test_manifiesto_sin_limite_acepta_devoluciones_sin_plazo(): void
    {
        // Transición 2026-07-21: los manifiestos anteriores a la entrada en
        // vigor quedaron con returns_deadline_at = NULL → SIN LÍMITE. Aunque
        // tengan semanas de antigüedad, siguen aceptando devoluciones.
        [$manifest, $invoice, $line] = $this->makeManifestConFactura(now()->subDays(15)->toDateString());

        DB::table('manifests')
            ->where('id', $manifest->id)
            ->update(['returns_deadline_at' => null]);

        $return = $this->service()->createReturn($this->createReturnData($invoice, $line));

        $this->assertSame('approved', $return->status);
        $this->assertFalse($manifest->fresh()->returnsWindowClosed());
    }

    public function test_sin_limite_no_se_rellena_solo_en_saves(): void
    {
        // El hook de update NO debe reponer el deadline de un manifiesto sin
        // límite: recalculateTotals() guarda constantemente y un refill
        // silencioso reactivaría la ventana sobre el backlog de transición.
        [$manifest] = $this->makeManifestConFactura(now()->subDays(15)->toDateString());

        DB::table('manifests')
            ->where('id', $manifest->id)
            ->update(['returns_deadline_at' => null]);

        $fresh = Manifest::findOrFail($manifest->id);
        $fresh->recalculateTotals(); // dispara saves del modelo

        $this->assertNull($fresh->fresh()->returns_deadline_at);
    }

    public function test_deadline_coincide_con_business_days(): void
    {
        // La columna persistida y el cálculo directo nunca deben divergir
        // (contrato del backfill y del hook saving()).
        $manifest = Manifest::factory()->create([
            'date' => '2026-07-18', // sábado → cierra jueves 23
            'warehouse_id' => $this->warehouse->id,
        ]);

        $this->assertEquals(
            BusinessDays::deadline('2026-07-18', (int) config('api.devoluciones_ventana_dias_habiles', 5))->timestamp,
            $manifest->returns_deadline_at->timestamp,
        );
    }
}
