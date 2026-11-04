<?php

namespace Tests\Feature\Services;

use App\Enums\ReturnPublicationMode;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnReason;
use App\Models\ReturnSetting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ReturnService;
use App\Services\ReturnSettingsService;
use App\Support\BusinessDays;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Configuración → Devoluciones (2026-11-04):
 *
 *   - Los días de ventana de un manifiesto salen del panel (sin elegir nada,
 *     del servidor) y se pueden aplicar a los manifiestos abiertos.
 *   - Cuándo ve Jaremar cada devolución: con el paquete del manifiesto (lo
 *     de siempre) o N días después de registrada (0 = al momento).
 *   - Lo que Jaremar ya puede ver no se corrige ni se cancela.
 *   - Cambiar el ajuste no mueve lo que ya estaba registrado.
 *
 * El TestCase base deja la ventana en 10000 días: aquí los manifiestos
 * siguen abiertos salvo que el test diga otra cosa.
 */
class ReturnPublicationTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key-publicacion';

    private Warehouse $warehouse;

    private ReturnReason $reason;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Miércoles 4 de noviembre de 2026, 3 pm.
        $this->travelTo(Carbon::parse('2026-11-04 15:00:00'));

        config([
            'api.jaremar_api_key' => self::API_KEY,
            'api.rate_limit_per_minute' => 100,
            'api.rate_limit_devoluciones_per_minute' => 100,
            'api.devoluciones_filtro_emision' => true,
        ]);

        $this->warehouse = Warehouse::factory()->create(['code' => 'OAC', 'name' => 'Copán']);
        $this->reason = ReturnReason::factory()->create();
        $this->user = User::factory()->create();
    }

    // ── Ayudantes ────────────────────────────────────────────────

    private function settings(): ReturnSettingsService
    {
        return app(ReturnSettingsService::class);
    }

    private function publicarDespuesDe(int $dias): void
    {
        $this->settings()->update(7, ReturnPublicationMode::AfterRegistration, $dias, false, $this->user);
    }

    /**
     * Registra una devolución hoy, por ReturnService (el camino real), de
     * una factura emitida hoy en un manifiesto que llegó hoy.
     */
    private function registrarDevolucion(): InvoiceReturn
    {
        $manifest = Manifest::factory()->create([
            'date' => now()->toDateString(),
            'status' => 'imported',
            'warehouse_id' => $this->warehouse->id,
        ]);

        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice_date' => now()->toDateString(),
        ]);

        $line = InvoiceLine::factory()->create([
            'invoice_id' => $invoice->id,
            'quantity_fractions' => 10,
            'quantity_box' => 0,
            'conversion_factor' => 1,
        ]);

        return app(ReturnService::class)->createReturn([
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
        ])->fresh();
    }

    /** IDs que devuelve el API para la fecha de emisión de hoy. */
    private function idsVisiblesParaJaremar(): array
    {
        Cache::flush();

        $response = $this->withHeaders([
            'ApiKey' => self::API_KEY,
            'Fecha' => Carbon::parse('2026-11-04')->format('d/m/Y'),
        ])->getJson(route('api.v1.devoluciones.listar'));

        $response->assertOk();

        return collect($response->json())->pluck('devolucion')->all();
    }

    private function cancelar(InvoiceReturn $return): void
    {
        app(ReturnService::class)->cancelReturn($return->fresh(), 'prueba');
    }

    // ── Ventana del manifiesto ───────────────────────────────────

    public function test_los_dias_de_ventana_salen_de_la_configuracion(): void
    {
        // Sin elegir nada en el panel: el valor del servidor.
        config(['api.devoluciones_ventana_dias_habiles' => 7]);
        $this->assertNull(ReturnSetting::current()->window_business_days);

        $conServidor = Manifest::factory()->create(['date' => '2026-07-24', 'warehouse_id' => $this->warehouse->id]);
        // Viernes 24: vie(1) sáb(2) lun(3) mar(4) mié(5) jue(6) vie(7).
        $this->assertSame('2026-07-31 23:59:59', $conServidor->returnsDeadline()->format('Y-m-d H:i:s'));

        $this->settings()->update(3, ReturnPublicationMode::Window, 1, false, $this->user);

        $conPanel = Manifest::factory()->create(['date' => '2026-07-24', 'warehouse_id' => $this->warehouse->id]);
        // vie(1) sáb(2) lun(3).
        $this->assertSame('2026-07-27 23:59:59', $conPanel->returnsDeadline()->format('Y-m-d H:i:s'));

        // El de antes conserva su cierre.
        $this->assertSame('2026-07-31 23:59:59', $conServidor->fresh()->returnsDeadline()->format('Y-m-d H:i:s'));
    }

    public function test_aplicar_a_los_abiertos_recalcula_solo_los_abiertos(): void
    {
        config(['api.devoluciones_ventana_dias_habiles' => 7]);

        // Llegó el lunes 2: con 7 días sigue abierto hasta el lunes 9.
        $abierto = Manifest::factory()->create(['date' => '2026-11-02', 'warehouse_id' => $this->warehouse->id]);

        $cerrado = Manifest::factory()->create(['date' => '2026-10-01', 'warehouse_id' => $this->warehouse->id]);
        $cerrado->update(['returns_deadline_at' => now()->subDay()]);
        $cierreDelCerrado = $cerrado->fresh()->returns_deadline_at;

        $sinLimite = Manifest::factory()->create(['date' => '2026-11-02', 'warehouse_id' => $this->warehouse->id]);
        $sinLimite->update(['returns_deadline_at' => null]);

        $cambiados = $this->settings()->update(2, ReturnPublicationMode::Window, 1, true, $this->user);

        $this->assertSame(1, $cambiados);

        // Con 2 días (lun 2, mar 3) ya pasó: queda cerrado al guardar.
        // Se compara hasta el segundo: BusinessDays da 23:59:59.999999 y la
        // base guarda 23:59:59 (como en los demás tests de la ventana).
        $abierto->refresh();
        $this->assertSame(
            BusinessDays::deadline('2026-11-02', 2)->format('Y-m-d H:i:s'),
            $abierto->returnsDeadline()->format('Y-m-d H:i:s'),
        );
        $this->assertSame('2026-11-03 23:59:59', $abierto->returnsDeadline()->format('Y-m-d H:i:s'));
        $this->assertTrue($abierto->returnsWindowClosed());

        $this->assertSame(
            $cierreDelCerrado->format('Y-m-d H:i:s'),
            $cerrado->fresh()->returns_deadline_at->format('Y-m-d H:i:s'),
        );
        $this->assertNull($sinLimite->fresh()->returns_deadline_at);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => ReturnSetting::class,
            'description' => 'Ventana de devoluciones de 2 días hábiles aplicada a 1 manifiestos abiertos',
        ]);
    }

    public function test_sin_marcar_aplicar_a_abiertos_no_toca_ningun_manifiesto(): void
    {
        config(['api.devoluciones_ventana_dias_habiles' => 7]);
        $abierto = Manifest::factory()->create(['date' => '2026-11-02', 'warehouse_id' => $this->warehouse->id]);
        $cierre = $abierto->fresh()->returns_deadline_at->format('Y-m-d H:i:s');

        $this->assertSame(0, $this->settings()->update(2, ReturnPublicationMode::Window, 1, false, $this->user));

        $this->assertSame($cierre, $abierto->fresh()->returns_deadline_at->format('Y-m-d H:i:s'));
    }

    public function test_rechaza_numeros_fuera_de_los_topes(): void
    {
        foreach ([[0, 1], [31, 1], [7, -1], [7, 31]] as [$ventana, $dias]) {
            try {
                $this->settings()->update($ventana, ReturnPublicationMode::AfterRegistration, $dias, false, $this->user);
                $this->fail("Debió rechazar ventana {$ventana} / {$dias} días.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNull(ReturnSetting::current()->window_business_days);
    }

    // ── Cuándo la ve Jaremar ─────────────────────────────────────

    public function test_por_defecto_sigue_el_paquete_del_manifiesto(): void
    {
        $return = $this->registrarDevolucion();

        $this->assertNull($return->published_at);
        // Ventana abierta (10000 días en tests): Jaremar no la ve todavía.
        $this->assertSame([], $this->idsVisiblesParaJaremar());
        $this->assertTrue($return->isEditableToday());
    }

    public function test_un_dia_despues_jaremar_la_ve_a_las_24_horas(): void
    {
        $this->publicarDespuesDe(1);

        $return = $this->registrarDevolucion();

        $this->assertSame('2026-11-05 15:00:00', $return->published_at->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->idsVisiblesParaJaremar());

        // Un minuto antes de las 24 horas: aún no la ve y todavía se cancela.
        $this->travelTo(Carbon::parse('2026-11-05 14:59:00'));
        $this->assertSame([], $this->idsVisiblesParaJaremar());
        $this->assertFalse($return->fresh()->isPublishedToJaremar());

        // A las 24 horas aparece, aunque el manifiesto siga abierto.
        $this->travelTo(Carbon::parse('2026-11-05 15:00:00'));
        $this->assertSame([(string) $return->id], $this->idsVisiblesParaJaremar());
        $this->assertFalse($return->manifest->fresh()->returnsWindowClosed());

        // Y ya no se cancela.
        $this->expectException(ValidationException::class);
        $this->cancelar($return);
    }

    public function test_antes_de_publicarse_todavia_se_puede_cancelar(): void
    {
        $this->publicarDespuesDe(1);
        $return = $this->registrarDevolucion();

        $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
        $this->cancelar($return);

        $this->assertTrue($return->fresh()->isCancelled());
    }

    public function test_al_momento_jaremar_la_ve_de_una_vez_y_queda_congelada(): void
    {
        $this->publicarDespuesDe(0);

        $return = $this->registrarDevolucion();

        $this->assertTrue($return->isPublishedToJaremar());
        $this->assertSame([(string) $return->id], $this->idsVisiblesParaJaremar());

        // Ni el mismo día se corrige.
        $this->assertFalse($return->isEditableToday());
        $this->assertSame('Bloqueada — Jaremar ya puede verla', $return->getEditabilityLabel());

        try {
            app(ReturnService::class)->updateReturn($return, [
                'return_reason_id' => $this->reason->id,
                'return_date' => now()->toDateString(),
                'lines' => [],
            ]);
            $this->fail('Debió rechazar la corrección.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Jaremar ya puede ver', $e->getMessage());
        }

        $this->expectException(ValidationException::class);
        $this->cancelar($return);
    }

    public function test_cambiar_el_ajuste_no_mueve_lo_ya_registrado(): void
    {
        $this->publicarDespuesDe(2);
        $return = $this->registrarDevolucion();

        $this->publicarDespuesDe(0);
        $this->settings()->update(7, ReturnPublicationMode::Window, 0, false, $this->user);

        $this->assertSame('2026-11-06 15:00:00', $return->fresh()->published_at->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->idsVisiblesParaJaremar());
    }

    public function test_el_modo_legacy_respeta_una_publicacion_futura(): void
    {
        config(['api.devoluciones_filtro_emision' => false]);
        $this->publicarDespuesDe(1);

        $return = $this->registrarDevolucion();

        // Legacy filtra por fecha de procesado (hoy): no la muestra antes de tiempo.
        $this->assertSame([], $this->idsVisiblesParaJaremar());

        $this->travelTo(Carbon::parse('2026-11-05 15:00:00'));
        $this->assertSame([(string) $return->id], $this->idsVisiblesParaJaremar());
    }
}
