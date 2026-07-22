<?php

namespace Tests\Feature\Api\V1;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnLine;
use App\Models\ReturnReason;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Contrato VIGENTE del listar de devoluciones (correo de Isack 2026-07-20):
 *
 *   1. El header Fecha filtra por fecha de EMISIÓN de la factura.
 *   2. Solo se publican manifiestos con la ventana de registro CERRADA
 *      (paquete completo por manifiesto; antes del cierre → vacío).
 *   3. Las cantidades SIEMPRE en unidades totales (cajas × factor + sueltas).
 *
 * El modo legacy (filtro por processed_date) se cubre en
 * DevolucionesControllerListarTest con el toggle en false.
 */
class DevolucionesControllerVentanaTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key-hozana-ventana';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api.jaremar_api_key' => self::API_KEY,
            'api.rate_limit_per_minute' => 100,
            'api.rate_limit_devoluciones_per_minute' => 100,
            'api.devoluciones_filtro_emision' => true,
        ]);
    }

    private function getListar(string $fecha)
    {
        return $this->withHeaders([
            'ApiKey' => self::API_KEY,
            'Fecha' => $fecha,
        ])->getJson(route('api.v1.devoluciones.listar'));
    }

    /**
     * Devolución aprobada con control total de fechas:
     * emisión de la factura, procesado, y cierre de ventana del manifiesto.
     */
    private function makeReturn(
        string $fechaEmision,
        bool $ventanaCerrada,
        array $lineOverrides = [],
        ?int $conversionFactor = null,
    ): InvoiceReturn {
        $warehouse = Warehouse::query()->where('code', 'OAC')->first()
            ?? Warehouse::factory()->create(['code' => 'OAC', 'name' => 'Copán']);

        $manifest = Manifest::factory()->create([
            'date' => $fechaEmision,
            'warehouse_id' => $warehouse->id,
        ]);

        // Controlar el cierre directamente: el hook saving() no recalcula
        // porque la fecha operativa no cambia en este update.
        $manifest->update([
            'returns_deadline_at' => $ventanaCerrada ? now()->subMinute() : now()->addDays(3),
        ]);

        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'invoice_date' => $fechaEmision,
        ]);

        $invoiceLine = null;
        if ($conversionFactor !== null) {
            $invoiceLine = InvoiceLine::factory()->create([
                'invoice_id' => $invoice->id,
                'conversion_factor' => $conversionFactor,
            ]);
        }

        $reason = ReturnReason::factory()->create();

        $return = InvoiceReturn::factory()
            ->approved()
            ->create([
                'manifest_id' => $manifest->id,
                'invoice_id' => $invoice->id,
                'warehouse_id' => $warehouse->id,
                'return_reason_id' => $reason->id,
                'client_id' => $invoice->client_id,
                'client_name' => $invoice->client_name,
                'processed_date' => now()->toDateString(),
                'processed_time' => '15:00:00',
                'return_date' => $fechaEmision.' 10:00:00',
                'total' => 100.0,
            ]);

        ReturnLine::create(array_merge([
            'return_id' => $return->id,
            'invoice_line_id' => $invoiceLine?->id,
            'line_number' => 1,
            'product_id' => 'P-0001',
            'product_description' => 'Producto de prueba',
            'quantity_box' => 0,
            'quantity' => 5,
            'line_total' => 100.0,
        ], $lineOverrides));

        return $return->refresh();
    }

    // ═══════════════════════════════════════════════════════════════
    //  PUNTO 1 — FILTRO POR FECHA DE EMISIÓN
    // ═══════════════════════════════════════════════════════════════

    public function test_filtra_por_fecha_de_emision_no_por_procesado(): void
    {
        // Factura emitida hace 12 días; la devolución se PROCESÓ hoy.
        $emision = now()->subDays(12)->toDateString();
        $this->makeReturn($emision, ventanaCerrada: true);

        // Consultar la fecha de EMISIÓN → aparece.
        $porEmision = $this->getListar(now()->subDays(12)->format('d/m/Y'));
        $porEmision->assertStatus(200);
        $this->assertCount(1, $porEmision->json());

        Cache::flush();

        // Consultar la fecha de PROCESADO (hoy) → NO aparece: el filtro
        // ya no es processed_date.
        $porProcesado = $this->getListar(now()->format('d/m/Y'));
        $porProcesado->assertStatus(200);
        $this->assertCount(0, $porProcesado->json());
    }

    // ═══════════════════════════════════════════════════════════════
    //  PUNTO 2 — PUBLICACIÓN SOLO CON VENTANA CERRADA
    // ═══════════════════════════════════════════════════════════════

    public function test_ventana_abierta_retiene_el_paquete(): void
    {
        $emision = now()->subDay()->toDateString();
        $return = $this->makeReturn($emision, ventanaCerrada: false);

        // Ventana abierta → la fecha responde vacío (nunca paquete parcial).
        $antes = $this->getListar(now()->subDay()->format('d/m/Y'));
        $antes->assertStatus(200);
        $this->assertCount(0, $antes->json());

        // Cerrar la ventana → el paquete completo aparece.
        $return->manifest->update(['returns_deadline_at' => now()->subMinute()]);
        Cache::flush(); // en producción la transición la absorbe el TTL corto (5 min)

        $despues = $this->getListar(now()->subDay()->format('d/m/Y'));
        $despues->assertStatus(200);
        $this->assertCount(1, $despues->json());
        $this->assertSame($return->manifest->number, $despues->json()[0]['numeroManifiesto']);
    }

    public function test_manifiesto_sin_limite_publica_de_inmediato(): void
    {
        // Transición 2026-07-21: manifiestos sin límite (deadline NULL)
        // conservan el esquema previo — sus devoluciones son visibles al
        // registrarse, sin esperar cierre de ventana.
        $emision = now()->subDay()->toDateString();
        $return = $this->makeReturn($emision, ventanaCerrada: false);

        $return->manifest->update(['returns_deadline_at' => null]);
        Cache::flush();

        $response = $this->getListar(now()->subDay()->format('d/m/Y'));
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $this->assertSame($return->manifest->number, $response->json()[0]['numeroManifiesto']);
    }

    // ═══════════════════════════════════════════════════════════════
    //  PUNTO 3 — CANTIDADES EN UNIDADES TOTALES
    // ═══════════════════════════════════════════════════════════════

    public function test_cantidad_en_unidades_totales_con_factor_de_conversion(): void
    {
        // 1 caja (factor 96) + 10 sueltas = 106 unidades — el ejemplo exacto.
        $emision = now()->subDays(10)->toDateString();
        $this->makeReturn(
            $emision,
            ventanaCerrada: true,
            lineOverrides: ['quantity_box' => 1, 'quantity' => 10],
            conversionFactor: 96,
        );

        $response = $this->getListar(now()->subDays(10)->format('d/m/Y'));
        $response->assertStatus(200);

        $raw = $response->getContent();
        $this->assertStringContainsString('"cantidad":106.000000', $raw);
    }

    public function test_linea_sin_factor_reporta_unidades_simples(): void
    {
        // Sin invoice_line vinculada → factor 1 (defensivo): 0 cajas + 5 = 5.
        $emision = now()->subDays(10)->toDateString();
        $this->makeReturn($emision, ventanaCerrada: true);

        $response = $this->getListar(now()->subDays(10)->format('d/m/Y'));
        $response->assertStatus(200);

        $this->assertStringContainsString('"cantidad":5.000000', $response->getContent());
    }
}
