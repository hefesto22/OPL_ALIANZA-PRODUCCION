<?php

namespace Tests\Feature\Api\V1;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnLine;
use App\Models\ReturnReason;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests end-to-end del endpoint outbound
 * GET /api/v1/devoluciones/listar.
 *
 * Jaremar llama este endpoint cada noche para sincronizar las devoluciones
 * aprobadas del sistema Hosana de vuelta a su ERP. Si devolvemos mal:
 *   - basura / pendientes / rechazadas → Jaremar credita al cliente de más
 *   - faltante → Jaremar no credita y el cliente reclama
 *   - campo mal escrito en la respuesta → integración rota en silencio
 *
 * Por eso cubrimos:
 *   - auth (ApiKey header requerido)
 *   - validación del header Fecha (faltante, formato inválido, válido)
 *   - scope: solo `approved` (pending/rejected NO aparecen)
 *   - filtro por `processed_date` (otras fechas NO aparecen)
 *   - shape de la respuesta (todos los campos que Jaremar espera)
 *   - selección de cantidad: quantity_box para CJ, quantity para UN
 *   - paginación opcional (Jaremar no la usa hoy pero está disponible)
 *   - vacío: fecha válida sin devoluciones → [] (no null, no 404)
 */
class DevolucionesControllerListarTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key-hozana-devoluciones';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api.jaremar_api_key' => self::API_KEY,
            'api.rate_limit_per_minute' => 100,
            'api.rate_limit_devoluciones_per_minute' => 100,
        ]);
    }

    /** Helper: GET con header ApiKey + Fecha. */
    private function getListar(?string $fecha, ?string $apiKey = self::API_KEY, array $query = [])
    {
        $headers = [];
        if ($apiKey !== null) {
            $headers['ApiKey'] = $apiKey;
        }
        if ($fecha !== null) {
            $headers['Fecha'] = $fecha;
        }

        $url = route('api.v1.devoluciones.listar');
        if (! empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        return $this->withHeaders($headers)->getJson($url);
    }

    /**
     * Crea una devolución aprobada completa: manifest + invoice + reason +
     * warehouse + return + 1 línea, con processed_date fijo.
     *
     * Devuelve el modelo InvoiceReturn ya con relaciones persistidas.
     */
    private function makeApprovedReturn(
        string $processedDate,
        string $warehouseCode = 'OAC',
        array $overrides = [],
        array $lineOverrides = []
    ): InvoiceReturn {
        $warehouse = Warehouse::where('code', $warehouseCode)->first()
            ?? Warehouse::factory()->create(['code' => $warehouseCode, 'name' => $warehouseCode]);

        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);

        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
            'invoice_number' => $overrides['invoice_number'] ?? 'F'.fake()->unique()->numerify('######'),
            'client_id' => $overrides['client_id'] ?? 'CLI-'.fake()->unique()->numerify('####'),
            'client_name' => $overrides['client_name'] ?? 'Cliente Test',
        ]);

        $reason = ReturnReason::factory()->create([
            'jaremar_id' => $overrides['reason_jaremar_id'] ?? '1001',
            'code' => 'BE-'.fake()->unique()->numerify('##'),
            'description' => $overrides['reason_description'] ?? 'Producto dañado en tránsito',
        ]);

        $return = InvoiceReturn::factory()
            ->approved()
            ->create([
                'manifest_id' => $manifest->id,
                'invoice_id' => $invoice->id,
                'warehouse_id' => $warehouse->id,
                'return_reason_id' => $reason->id,
                'client_id' => $invoice->client_id,
                'client_name' => $invoice->client_name,
                'processed_date' => $processedDate,
                'processed_time' => $overrides['processed_time'] ?? '14:30:00',
                'return_date' => $overrides['return_date'] ?? $processedDate.' 10:00:00',
                'total' => $overrides['total'] ?? 450.75,
            ]);

        ReturnLine::create(array_merge([
            'return_id' => $return->id,
            'line_number' => 1,
            'product_id' => 'P-0001',
            'product_description' => 'Producto de prueba',
            'quantity_box' => 0,
            'quantity' => 5,
            'line_total' => 450.75,
        ], $lineOverrides));

        return $return->refresh();
    }

    // ── Auth ───────────────────────────────────────────────────────────

    public function test_listar_requires_api_key_header(): void
    {
        // Sin header ApiKey → middleware ValidateApiKey devuelve 401.
        $response = $this->getJson(route('api.v1.devoluciones.listar'), [
            'Fecha' => '10/04/2026',
        ]);

        $response->assertStatus(401);
    }

    public function test_listar_rejects_invalid_api_key(): void
    {
        $response = $this->getListar('10/04/2026', apiKey: 'clave-incorrecta');
        $response->assertStatus(401);
    }

    // ── Header Fecha ───────────────────────────────────────────────────

    public function test_listar_rejects_missing_fecha_header(): void
    {
        $response = $this->getListar(fecha: null);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonPath('message', fn ($m) => str_contains($m, 'Fecha'));
    }

    public function test_listar_rejects_invalid_fecha_format(): void
    {
        // El controller espera dd/MM/yyyy, esto es ISO — debe romper.
        $response = $this->getListar('2026-04-10');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', fn ($m) => str_contains($m, 'dd/MM/yyyy'));
    }

    public function test_listar_rejects_nonsense_fecha(): void
    {
        $response = $this->getListar('99/99/9999');
        $response->assertStatus(422);
    }

    // ── Happy path: vacío ──────────────────────────────────────────────

    public function test_listar_returns_empty_array_when_no_returns_on_that_date(): void
    {
        // Fecha válida pero no hay devoluciones → debe devolver [] con 200.
        $response = $this->getListar('10/04/2026');

        $response->assertStatus(200);
        $this->assertSame([], $response->json());
    }

    // ── Happy path: con devoluciones ───────────────────────────────────

    public function test_listar_returns_approved_returns_for_the_requested_date(): void
    {
        $this->makeApprovedReturn('2026-04-10', 'OAC', [
            'invoice_number' => 'F00001',
            'client_id' => 'CLI-A',
            'client_name' => 'Cliente A',
            'total' => 200.50,
        ]);
        $this->makeApprovedReturn('2026-04-10', 'OAS', [
            'invoice_number' => 'F00002',
            'client_id' => 'CLI-B',
            'client_name' => 'Cliente B',
            'total' => 700.00,
        ]);

        $response = $this->getListar('10/04/2026');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(2, $data);

        // Las dos devoluciones deben estar representadas por invoice_number.
        $invoices = array_column($data, 'factura');
        sort($invoices);
        $this->assertSame(['F00001', 'F00002'], $invoices);
    }

    public function test_listar_response_has_expected_shape(): void
    {
        $this->makeApprovedReturn('2026-04-10', 'OAC', [
            'invoice_number' => 'F77777',
            'client_id' => 'CLI-X',
            'client_name' => 'Cliente X',
            'reason_jaremar_id' => '9001',
            'reason_description' => 'Rechazo por fecha vencida',
            'processed_time' => '09:15:00',
            'total' => 1234.56,
        ]);

        $response = $this->getListar('10/04/2026');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            '*' => [
                'devolucion',
                'factura',
                'clienteid',
                'cliente',
                'fecha',
                'total',
                'almacen',
                'idConcepto',
                'concepto',
                'numeroManifiesto',
                'fechaProcesado',
                'horaProcesado',
                'lineasDevolucion' => [
                    '*' => ['productoId', 'producto', 'cantidad', 'numeroLinea', 'lineTotal'],
                ],
            ],
        ]);

        // Valores puntuales — confirmamos que no estamos devolviendo nulls donde hay dato.
        $first = $response->json(0);
        $this->assertSame('F77777', $first['factura']);
        $this->assertSame('CLI-X', $first['clienteid']);
        $this->assertSame('Cliente X', $first['cliente']);
        $this->assertSame('OAC', $first['almacen']);
        $this->assertSame('9001', $first['idConcepto']);
        $this->assertSame('Rechazo por fecha vencida', $first['concepto']);
        $this->assertEqualsWithDelta(1234.56, $first['total'], 0.001);
    }

    // ── Scope: solo approved ──────────────────────────────────────────

    public function test_listar_excludes_pending_and_rejected_returns(): void
    {
        // Una aprobada (sí aparece).
        $this->makeApprovedReturn('2026-04-10', 'OAC', ['invoice_number' => 'F-APPROVED']);

        // Una pendiente (no aparece).
        $warehouse = Warehouse::where('code', 'OAC')->first();
        $manifestP = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);
        $invoiceP = Invoice::factory()->create([
            'manifest_id' => $manifestP->id,
            'warehouse_id' => $warehouse->id,
            'invoice_number' => 'F-PENDING',
        ]);
        InvoiceReturn::factory()->create([
            'manifest_id' => $manifestP->id,
            'invoice_id' => $invoiceP->id,
            'warehouse_id' => $warehouse->id,
            'return_reason_id' => ReturnReason::factory()->create()->id,
            'status' => 'pending',
            'processed_date' => '2026-04-10',
        ]);

        // Una rechazada (no aparece).
        $manifestR = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);
        $invoiceR = Invoice::factory()->create([
            'manifest_id' => $manifestR->id,
            'warehouse_id' => $warehouse->id,
            'invoice_number' => 'F-REJECTED',
        ]);
        InvoiceReturn::factory()->rejected()->create([
            'manifest_id' => $manifestR->id,
            'invoice_id' => $invoiceR->id,
            'warehouse_id' => $warehouse->id,
            'return_reason_id' => ReturnReason::factory()->create()->id,
            'processed_date' => '2026-04-10',
        ]);

        $response = $this->getListar('10/04/2026');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertSame('F-APPROVED', $data[0]['factura']);
    }

    // ── Filtro por processed_date ─────────────────────────────────────

    public function test_listar_only_returns_results_for_matching_processed_date(): void
    {
        $this->makeApprovedReturn('2026-04-10', 'OAC', ['invoice_number' => 'F-HOY']);
        $this->makeApprovedReturn('2026-04-09', 'OAC', ['invoice_number' => 'F-AYER']);
        $this->makeApprovedReturn('2026-04-11', 'OAC', ['invoice_number' => 'F-MANIANA']);

        $response = $this->getListar('10/04/2026');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertSame('F-HOY', $data[0]['factura']);
    }

    public function test_listar_excludes_approved_returns_without_processed_date(): void
    {
        // Devolución aprobada pero sin processed_date (edge case: aprobada
        // pero el processor aún no corrió). No debe aparecer en ninguna
        // consulta por fecha.
        $warehouse = Warehouse::where('code', 'OAC')->first()
            ?? Warehouse::factory()->create(['code' => 'OAC', 'name' => 'OAC']);
        $manifest = Manifest::factory()->create(['warehouse_id' => $warehouse->id]);
        $invoice = Invoice::factory()->create([
            'manifest_id' => $manifest->id,
            'warehouse_id' => $warehouse->id,
        ]);
        InvoiceReturn::factory()->approved()->create([
            'manifest_id' => $manifest->id,
            'invoice_id' => $invoice->id,
            'warehouse_id' => $warehouse->id,
            'return_reason_id' => ReturnReason::factory()->create()->id,
            'processed_date' => null,
        ]);

        $response = $this->getListar('10/04/2026');
        $response->assertStatus(200);
        $this->assertSame([], $response->json());
    }

    // ── Lógica de cantidad (CJ vs UN) ──────────────────────────────────

    public function test_listar_uses_quantity_box_for_cj_products(): void
    {
        // CJ products: quantity=0, quantity_box>0 → cantidad=quantity_box.
        $this->makeApprovedReturn('2026-04-10', 'OAC', ['invoice_number' => 'F-CJ'], [
            'product_id' => 'CJ-PROD',
            'quantity_box' => 3,
            'quantity' => 0,
            'line_total' => 300.00,
        ]);

        $response = $this->getListar('10/04/2026');
        $response->assertStatus(200);
        $line = $response->json('0.lineasDevolucion.0');
        $this->assertEqualsWithDelta(3.0, $line['cantidad'], 0.001);
    }

    public function test_listar_uses_quantity_for_un_products(): void
    {
        // UN products: quantity_box=0, quantity>0 → cantidad=quantity.
        $this->makeApprovedReturn('2026-04-10', 'OAC', ['invoice_number' => 'F-UN'], [
            'product_id' => 'UN-PROD',
            'quantity_box' => 0,
            'quantity' => 12,
            'line_total' => 240.00,
        ]);

        $response = $this->getListar('10/04/2026');
        $response->assertStatus(200);
        $line = $response->json('0.lineasDevolucion.0');
        $this->assertEqualsWithDelta(12.0, $line['cantidad'], 0.001);
    }

    // ── Paginación ─────────────────────────────────────────────────────

    public function test_listar_pagination_defaults_to_page_one(): void
    {
        // Sin ?pagina, entrega todo (bajo el límite de 1000).
        $this->makeApprovedReturn('2026-04-10', 'OAC', ['invoice_number' => 'F-1']);
        $this->makeApprovedReturn('2026-04-10', 'OAC', ['invoice_number' => 'F-2']);
        $this->makeApprovedReturn('2026-04-10', 'OAC', ['invoice_number' => 'F-3']);

        $response = $this->getListar('10/04/2026');
        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    public function test_listar_pagination_page_two_is_empty_when_under_limit(): void
    {
        // Con solo 3 devoluciones y un límite de 1000, la página 2 debe
        // estar vacía (y no romper el endpoint).
        $this->makeApprovedReturn('2026-04-10', 'OAC', ['invoice_number' => 'F-1']);
        $this->makeApprovedReturn('2026-04-10', 'OAC', ['invoice_number' => 'F-2']);

        $response = $this->getListar('10/04/2026', query: ['pagina' => 2]);
        $response->assertStatus(200);
        $this->assertSame([], $response->json());
    }
}
