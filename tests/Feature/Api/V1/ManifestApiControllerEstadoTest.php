<?php

namespace Tests\Feature\Api\V1;

use App\Models\Manifest;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests del endpoint outbound
 * GET /api/v1/manifiestos/{numero}/estado.
 *
 * Jaremar usa este endpoint para consultar el estado de un manifiesto
 * específico — por ejemplo, cuando quiere saber si un batch que mandó
 * anoche ya fue procesado y cerrado. La response expone el status del
 * manifest más un resumen con total_facturas, total_importe y
 * fecha_ingreso, lo cual se lee del contador y del acumulado en BD.
 *
 * Cubrimos:
 *   - auth (ApiKey requerido por el middleware)
 *   - manifiesto inexistente → 404 con mensaje legible
 *   - manifiesto existente → 200 con shape correcto y valores reales
 *   - ruta constraint: el parámetro {numero} está limitado a \d+, no se
 *     puede llegar al controller con un valor no numérico (404 de Laravel)
 */
class ManifestApiControllerEstadoTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-api-key-hozana-estado';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api.jaremar_api_key' => self::API_KEY,
            'api.rate_limit_per_minute' => 100,
        ]);
    }

    private function getEstado(string $numero, ?string $apiKey = self::API_KEY)
    {
        $headers = [];
        if ($apiKey !== null) {
            $headers['ApiKey'] = $apiKey;
        }

        return $this->withHeaders($headers)
            ->getJson("/api/v1/manifiestos/{$numero}/estado");
    }

    // ── Auth ───────────────────────────────────────────────────────────

    public function test_estado_requires_api_key_header(): void
    {
        $response = $this->getJson('/api/v1/manifiestos/123/estado');
        $response->assertStatus(401);
    }

    public function test_estado_rejects_invalid_api_key(): void
    {
        $response = $this->getEstado('123', apiKey: 'clave-mala');
        $response->assertStatus(401);
    }

    // ── Manifiesto inexistente ────────────────────────────────────────

    public function test_estado_returns_404_for_nonexistent_manifest(): void
    {
        $response = $this->getEstado('999999');

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', fn ($m) => str_contains($m, '999999'));
    }

    // ── Manifiesto existente ──────────────────────────────────────────

    public function test_estado_returns_summary_for_existing_manifest(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => 'OAC', 'name' => 'OAC']);

        // Los estados válidos (según migration manifests_status_check) son:
        // pending, processing, imported, closed. "imported" es el estado
        // activo normal cuando Jaremar termina de mandar todo.
        $manifest = Manifest::factory()->create([
            'number' => '555001',
            'warehouse_id' => $warehouse->id,
            'status' => 'imported',
            'invoices_count' => 3,
            'total_invoices' => 1234.56,
        ]);

        $response = $this->getEstado('555001');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'manifiesto',
            'estado',
            'resumen' => ['total_facturas', 'total_importe', 'fecha_ingreso'],
        ]);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('manifiesto', '555001');
        $response->assertJsonPath('estado', 'imported');
        $response->assertJsonPath('resumen.total_facturas', 3);
        $response->assertJsonPath('resumen.total_importe', 1234.56);
    }

    public function test_estado_reflects_closed_status(): void
    {
        // Un manifest ya cerrado debe devolver su status "closed" tal cual,
        // para que Jaremar pueda diferenciar "aún abierto" de "ya procesado".
        $warehouse = Warehouse::factory()->create(['code' => 'OAS', 'name' => 'OAS']);

        Manifest::factory()->create([
            'number' => '555002',
            'warehouse_id' => $warehouse->id,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $response = $this->getEstado('555002');

        $response->assertStatus(200);
        $response->assertJsonPath('estado', 'closed');
    }

    // ── Ruta: parámetro {numero} constraint \d+ ────────────────────────

    public function test_estado_route_rejects_non_numeric_manifest_number(): void
    {
        // La ruta tiene ->where('numero', '[0-9]+'), así que valores
        // no-numéricos devuelven 404 de Laravel (no llegan al controller).
        $response = $this->getEstado('ABC123');
        $response->assertStatus(404);
    }
}
