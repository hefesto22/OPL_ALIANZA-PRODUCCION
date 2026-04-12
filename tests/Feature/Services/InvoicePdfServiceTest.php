<?php

namespace Tests\Feature\Services;

use App\Models\Manifest;
use App\Models\Supplier;
use App\Services\InvoicePdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests para InvoicePdfService.
 *
 * Servicio ligero que genera la URL cifrada para la impresión de
 * facturas vía browser. Si la URL se genera mal, el operador no
 * puede imprimir y se atrasa la entrega a los clientes.
 */
class InvoicePdfServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::create(['name' => 'admin',       'guard_name' => 'web']);
    }

    private function service(): InvoicePdfService
    {
        return $this->app->make(InvoicePdfService::class);
    }

    public function test_generatePrintUrl_contains_encrypted_manifest_id(): void
    {
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $manifest = Manifest::factory()->create(['supplier_id' => $supplier->id]);

        $url = $this->service()->generatePrintUrl($manifest);

        // La URL debe contener el payload cifrado como query param
        $this->assertStringContainsString('payload=', $url);

        // Extraer y descifrar el payload
        $parsed  = parse_url($url);
        parse_str($parsed['query'] ?? '', $params);
        $payload = json_decode(Crypt::decryptString($params['payload']), true);

        $this->assertSame($manifest->id, $payload['manifest_id']);
        $this->assertSame([], $payload['invoice_ids']);
    }

    public function test_generatePrintUrl_includes_specific_invoice_ids(): void
    {
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $manifest = Manifest::factory()->create(['supplier_id' => $supplier->id]);

        $url = $this->service()->generatePrintUrl($manifest, [10, 20, 30]);

        $parsed  = parse_url($url);
        parse_str($parsed['query'] ?? '', $params);
        $payload = json_decode(Crypt::decryptString($params['payload']), true);

        $this->assertSame([10, 20, 30], $payload['invoice_ids']);
    }

    public function test_filename_contains_manifest_number(): void
    {
        $supplier = Supplier::factory()->create(['is_active' => true]);
        $manifest = Manifest::factory()->create([
            'supplier_id' => $supplier->id,
            'number'      => 'MAN-PDF-001',
        ]);

        $filename = $this->service()->filename($manifest);

        $this->assertStringStartsWith('facturas_manifiesto_MAN-PDF-001_', $filename);
        $this->assertStringEndsWith('.pdf', $filename);
    }
}
