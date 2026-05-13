<?php

namespace Tests\Feature\Http;

use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Tests del PrintInvoicesController.
 *
 * Cubre los 3 hardenings del bloque #5:
 *   1. show() ya NO marca is_printed — eso es trabajo del callback.
 *   2. count guard rechaza requests con > 1000 facturas.
 *   3. rate limit (throttle:print-invoices) bloquea ráfagas.
 *
 * Más:
 *   4. confirm() marca correctamente y aísla por bodega.
 *
 * Notas:
 *   - El payload viaja cifrado con Crypt::encryptString. Los tests usan
 *     Crypt para generar payloads válidos sin duplicar la lógica del
 *     controller.
 *   - El rate limit por defecto es 30/min; los tests bajan el valor a
 *     1 para no hacer 31 requests reales.
 */
class PrintInvoicesControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Warehouse $warehouseOAC;

    protected Warehouse $warehouseOAS;

    protected Manifest $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        // Rate limit alto por defecto para no tropezar con throttle en tests
        // que no son del rate limit. El test dedicado lo baja a 1.
        config(['api.rate_limit_print_per_minute' => 100]);

        Supplier::factory()->create(['is_active' => true]);

        $this->warehouseOAC = Warehouse::factory()->oac()->create();
        $this->warehouseOAS = Warehouse::factory()->oas()->create();

        $this->user = User::factory()->create();

        $this->manifest = Manifest::factory()->create([
            'warehouse_id' => $this->warehouseOAC->id,
        ]);
    }

    /**
     * Helper: arma el query string ?payload=... con el formato cifrado
     * que espera el controller.
     */
    private function encryptedPayload(array $data): string
    {
        return Crypt::encryptString(json_encode($data));
    }

    // ══════════════════════════════════════════════════════════════
    //  show — happy path y count guard
    // ══════════════════════════════════════════════════════════════

    public function test_show_returns_200_with_valid_payload(): void
    {
        Invoice::factory()->count(3)
            ->for($this->manifest, 'manifest')
            ->for($this->warehouseOAC, 'warehouse')
            ->create();

        $payload = $this->encryptedPayload([
            'manifest_id' => $this->manifest->id,
            'invoice_ids' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('invoices.print', ['payload' => $payload]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test_show_does_not_mark_invoices_as_printed(): void
    {
        // Cambio central de este sprint: la vista NO marca is_printed.
        // El callback JS lo hace después de window.afterprint.
        $invoices = Invoice::factory()->count(2)
            ->for($this->manifest, 'manifest')
            ->for($this->warehouseOAC, 'warehouse')
            ->create();

        $payload = $this->encryptedPayload([
            'manifest_id' => $this->manifest->id,
            'invoice_ids' => $invoices->pluck('id')->all(),
        ]);

        $this->actingAs($this->user)
            ->get(route('invoices.print', ['payload' => $payload]))
            ->assertOk();

        foreach ($invoices as $invoice) {
            $this->assertFalse((bool) $invoice->fresh()->is_printed);
            $this->assertNull($invoice->fresh()->printed_at);
        }
    }

    public function test_show_returns_422_when_invoice_ids_exceeds_max(): void
    {
        // Tope configurable; el test fija un valor bajo para evitar crear
        // 1001 invoices innecesariamente.
        config(['api.print_max_invoices_per_request' => 3]);

        // 4 ids específicos en el payload → debe rechazar antes de cargar.
        $payload = $this->encryptedPayload([
            'manifest_id' => $this->manifest->id,
            'invoice_ids' => [1, 2, 3, 4],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('invoices.print', ['payload' => $payload]));

        $response->assertStatus(422);
    }

    public function test_show_returns_422_when_manifest_has_too_many_invoices_without_filter(): void
    {
        // Sin invoice_ids → cuenta sobre el manifest entero. Si excede
        // el tope, rechazo igual que el caso anterior.
        config(['api.print_max_invoices_per_request' => 2]);

        Invoice::factory()->count(5)
            ->for($this->manifest, 'manifest')
            ->for($this->warehouseOAC, 'warehouse')
            ->create();

        $payload = $this->encryptedPayload([
            'manifest_id' => $this->manifest->id,
            'invoice_ids' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('invoices.print', ['payload' => $payload]));

        $response->assertStatus(422);
    }

    public function test_show_returns_429_after_exceeding_rate_limit(): void
    {
        // Bajar el límite a 1/min y hacer 2 GETs — el segundo debe 429.
        config(['api.rate_limit_print_per_minute' => 1]);

        Invoice::factory()
            ->for($this->manifest, 'manifest')
            ->for($this->warehouseOAC, 'warehouse')
            ->create();

        $payload = $this->encryptedPayload([
            'manifest_id' => $this->manifest->id,
            'invoice_ids' => [],
        ]);

        $url = route('invoices.print', ['payload' => $payload]);

        $this->actingAs($this->user)->get($url)->assertOk();

        $second = $this->actingAs($this->user)->get($url);
        $second->assertStatus(429);
    }

    public function test_show_returns_403_for_invalid_encrypted_payload(): void
    {
        // Payload basura → decrypt lanza, controller responde 403.
        $response = $this->actingAs($this->user)
            ->get(route('invoices.print', ['payload' => 'not-a-valid-encrypted-string']));

        $response->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  confirm — marcado real + aislamiento por bodega
    // ══════════════════════════════════════════════════════════════

    public function test_confirm_marks_invoices_as_printed(): void
    {
        $invoices = Invoice::factory()->count(2)
            ->for($this->manifest, 'manifest')
            ->for($this->warehouseOAC, 'warehouse')
            ->create();

        $response = $this->actingAs($this->user)
            ->postJson(route('invoices.print.confirm'), [
                'invoice_ids' => $invoices->pluck('id')->all(),
            ]);

        $response->assertNoContent();

        foreach ($invoices as $invoice) {
            $fresh = $invoice->fresh();
            $this->assertTrue((bool) $fresh->is_printed);
            $this->assertNotNull($fresh->printed_at);
        }
    }

    public function test_confirm_does_not_mark_invoices_outside_user_warehouse(): void
    {
        // Operador OAC intenta marcar una factura de OAS — el
        // WarehouseScope filtra y el update no la toca.
        $manifestOAS = Manifest::factory()->create([
            'warehouse_id' => $this->warehouseOAS->id,
        ]);
        $invoiceOAS = Invoice::factory()
            ->for($manifestOAS, 'manifest')
            ->for($this->warehouseOAS, 'warehouse')
            ->create();

        $operatorOAC = User::factory()->create(['warehouse_id' => $this->warehouseOAC->id]);

        $response = $this->actingAs($operatorOAC)
            ->postJson(route('invoices.print.confirm'), [
                'invoice_ids' => [$invoiceOAS->id],
            ]);

        // 204 — el endpoint completa, pero el update se filtró por scope
        // y no afectó la factura ajena.
        $response->assertNoContent();
        $this->assertFalse((bool) $invoiceOAS->fresh()->is_printed);
        $this->assertNull($invoiceOAS->fresh()->printed_at);
    }

    public function test_confirm_rejects_invalid_payload(): void
    {
        // Validación: invoice_ids es obligatorio y debe ser array.
        $response = $this->actingAs($this->user)
            ->postJson(route('invoices.print.confirm'), [
                'invoice_ids' => 'not-an-array',
            ]);

        $response->assertStatus(422);
    }

    public function test_confirm_requires_authentication(): void
    {
        // Sin actingAs — middleware auth bloquea.
        $response = $this->postJson(route('invoices.print.confirm'), [
            'invoice_ids' => [1],
        ]);

        // 302 (redirect login) o 401 según config; ambos son "no auth".
        $this->assertContains($response->status(), [302, 401]);
    }
}
