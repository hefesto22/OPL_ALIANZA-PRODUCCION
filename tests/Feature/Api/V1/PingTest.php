<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;

/**
 * Feature test del endpoint de health check GET /api/v1/ping.
 *
 * Este es el único endpoint de la API v1 que NO requiere ApiKey:
 *   - Lo usa Jaremar/infra para monitoreo antes de mandar payloads.
 *   - Si se cae este test, es señal de que alguien protegió toda la
 *     API con middleware de auth sin dejar una puerta de diagnóstico.
 *
 * Validamos:
 *   - 200 OK
 *   - Estructura JSON completa (status/service/version/timestamp)
 *   - timestamp en formato ISO 8601
 *   - No requiere API key
 */
class PingTest extends TestCase
{
    public function test_ping_returns_ok_without_api_key(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
            'service' => 'Distribuidora Hosana API',
            'version' => 'v1',
        ]);
        $response->assertJsonStructure([
            'status',
            'service',
            'version',
            'timestamp',
        ]);
    }

    public function test_ping_timestamp_is_iso8601(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $timestamp = $response->json('timestamp');
        $this->assertIsString($timestamp);

        // ISO 8601 con offset: 2026-04-11T12:34:56-06:00 (o +00:00)
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $timestamp
        );
    }

    public function test_ping_ignores_invalid_api_key_header(): void
    {
        // Aunque el cliente mande una ApiKey inválida, el ping es público
        // y debe responder 200 igual.
        $response = $this->getJson('/api/v1/ping', [
            'ApiKey' => 'definitely-not-a-real-key',
        ]);

        $response->assertStatus(200);
    }
}
