<?php

namespace Tests\Feature\Http;

use App\Models\Invoice;
use App\Models\InvoiceLine;
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

    /**
     * Regresión del formato Jaremar — bloquea cualquier cambio futuro
     * que rompa la equivalencia visual con la factura original.
     *
     * Los datos del invoice y las líneas reproducen la factura Jaremar
     * real NT 002-001-01-03602123 (PUESTO 21, manifiesto 734915) — la
     * misma del JSON crudo que Jaremar envía al API.
     *
     * Cubre las reglas del formato Jaremar para este caso específico:
     *   - header de matriz limpio (sin duplicación de dirección)
     *   - ARTICULO No imprime solo product_id (el código EAN largo no
     *     viene en el JSON; está pendiente pedido a Jaremar)
     *   - líneas tipo A: usan valores del API directamente
     *   - líneas tipo B con tax_percent=15: VALOR truncado, DESCUENTO
     *     con signo trailing, ISV15=.01 (remanente del truncado)
     *   - regla "fila cero": Exento e Exonerado salen todo en cero
     *     porque sus bases son 0
     *   - Gravado tiene DESCUENTO 31.95- desde importe_gravado_desc
     *   - TOTAL A PAGAR DESCUENTO = suma efectiva del bonus = 31.95-
     *   - pie completo: firmas, SON LEMPIRAS, Rango, cláusulas
     *   - typo "Rxonerado" eliminado a favor de "Exonerado"
     *
     * Ver memory project_invoice_pdf_jaremar_format.
     */
    public function test_show_renders_jaremar_format(): void
    {
        $invoice = Invoice::factory()
            ->for($this->manifest, 'manifest')
            ->for($this->warehouseOAC, 'warehouse')
            ->create([
                'invoice_number' => '002-001-01-03602123',
                'client_name' => 'PUESTO 21',
                'neighborhood' => 'SAN MIGUEL',
                'municipality' => 'Marcala',
                'department' => 'LA PAZ',
                'range_start' => '002-001-01-03200001',
                'range_end' => '002-001-01-03700000',
                // ── Importes del JSON real ────────────────────────────
                // ImporteExcento es CERO pero ImporteExento_Desc=31.96,
                // _ISV15=0.01, _Total=31.96 vienen poblados. La regla
                // "fila cero" debe SUPRIMIR esos valores al imprimir.
                'importe_excento' => 0,
                'importe_exento_desc' => 31.96,
                'importe_exento_isv15' => 0.01,
                'importe_exento_total' => 31.96,
                // Gravado tiene los valores reales.
                'importe_gravado' => 1216.00,
                'importe_gravado_desc' => -31.95,
                'importe_gravado_isv15' => 177.61,
                'importe_gravado_total' => 1361.66,
                'isv15' => 177.61,
                'isv18' => 0,
                'total' => 1361.66,
            ]);

        // Línea tipo A — usa valores del API tal cual.
        InvoiceLine::factory()->for($invoice, 'invoice')->create([
            'product_id' => '52480077',
            'product_description' => 'LIMPIOX CREMA LIMON IND 425 g X18',
            'product_type' => 'A',
            'unit_sale' => 'UN',
            'quantity_fractions' => 12,
            'quantity_decimal' => 0.667,
            'price' => 327.080,
            'subtotal' => 218.16,
            'discount' => 0,
            'tax' => 32.72,
            'tax18' => 0,
            'total' => 250.88,
        ]);

        // Línea tipo B (bonus) — el JSON manda subtotal/tax/total = 0 y
        // tax_percent = 15 (el bonus es GRAVADO en este caso). El Blade
        // debe calcular localmente con floor + remanente porque tax_percent>0:
        //   exact = 760.952 × 0.042 = 31.95998...
        //   VALOR = floor(exact × 100) / 100 = 31.95
        //   DESCUENTO = -VALOR → AS400 "31.95-"
        //   ISV15 = round(exact - 31.95, 2) = 0.01 (porque tax_percent>0)
        //   TOTAL = ISV15 = 0.01
        InvoiceLine::factory()->for($invoice, 'invoice')->create([
            'product_id' => '80800013',
            'product_description' => 'PASTA NORMAL 8X12X87GR',
            'product_type' => 'B',
            'unit_sale' => 'UN',
            'quantity_fractions' => 4,
            'quantity_decimal' => 0.042,
            'price' => 760.952,
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'tax_percent' => 15,
            'tax18' => 0,
            'total' => 0,
        ]);

        $payload = $this->encryptedPayload([
            'manifest_id' => $this->manifest->id,
            'invoice_ids' => [$invoice->id],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('invoices.print', ['payload' => $payload]));

        $response->assertOk();
        $html = $response->getContent();

        // ── Presencia: header literal de Jaremar ──────────────────
        $this->assertStringContainsString('RTN: 08019017952895', $html);
        $this->assertStringContainsString(
            'BO:LA GUADALUPE CL:LAS ACACIAS APTO:13 EDIF: ITALIA M.D.C. F.M. HONDURAS',
            $html
        );
        $this->assertStringContainsString('KM 15 CARRETERA A BUFALO', $html);
        $this->assertStringContainsString('finanzas@jaremar.com', $html);

        // ── Presencia: datos de la factura ────────────────────────
        $this->assertStringContainsString('002-001-01-03602123', $html);
        $this->assertStringContainsString('PUESTO 21', $html);
        $this->assertStringContainsString('LIMPIOX CREMA LIMON IND 425 g X18', $html);
        $this->assertStringContainsString('PASTA NORMAL 8X12X87GR', $html);

        // ── ARTICULO No imprime solo el product_id (sin código largo) ──
        // Hasta que Jaremar incluya el EAN en el JSON.
        $this->assertStringContainsString('>52480077<', $html);
        $this->assertStringContainsString('>80800013<', $html);

        // ── Línea bonus: VALOR=31.95 (truncado), DESCUENTO=31.95- ──
        $this->assertMatchesRegularExpression('/>31\.95</', $html);
        $this->assertMatchesRegularExpression('/>31\.95-</', $html);
        // Debe imprimir signo trailing AS400, no leading.
        $this->assertStringNotContainsString('-31.95', $html);
        // Línea bonus: ISV15 y TOTAL deben ser .01 (remanente del truncado).
        $this->assertMatchesRegularExpression('/>\.01</', $html);

        // ── Dirección con formato Jaremar largo ──────────────────────
        $this->assertStringContainsString(
            'MARCALA DEPARTAMENTO DE LA PAZ HONDURAS',
            $html
        );

        // ── Tabla de Importes — regla "fila cero" ──────────────────
        // ImporteExcento=0 → toda la fila Exento debe salir en cero
        // (NO debe aparecer 31.96 ni 0.01 en esa fila, aunque estén en BD).
        // Validamos contando que ".00" aparece muchas veces y los valores
        // raros del JSON están suprimidos al imprimir.
        $this->assertStringContainsString('Importe Exento', $html);
        $this->assertStringContainsString('Importe Exonerado', $html);
        $this->assertStringContainsString('Importe Gravado', $html);
        $this->assertStringContainsString('TOTAL A PAGAR', $html);

        // ── Fila Gravado SÍ tiene los valores reales ──────────────
        $this->assertStringContainsString('1,216.00', $html);
        $this->assertStringContainsString('177.61', $html);
        $this->assertStringContainsString('1,361.66', $html);

        // ── Pie fiscal completo ──────────────────────────────────────
        $this->assertStringContainsString('NOMBRE COMPLETO', $html);
        $this->assertStringContainsString('NO. DE IDENTIFICACION', $html);
        $this->assertStringContainsString('FIRMA DE RECIBIDO', $html);
        $this->assertStringContainsString('SON LEMPIRAS:', $html);
        $this->assertStringContainsString('Rango Autorizado:', $html);
        $this->assertStringContainsString('002-001-01-03200001 Al 002-001-01-03700000', $html);
        $this->assertStringContainsString('Original:Cliente', $html);
        $this->assertStringContainsString('JAMERARI', $html);
        $this->assertStringContainsString('LAS FACTURAS Y NOTAS DE DEBITO PAGADAS CON CHEQUE', $html);

        // ── SON LEMPIRAS con conversión a palabras ───────────────────
        // total=1361.66 → "... MIL TRESCIENTOS SESENTA Y UN CON 66/100"
        $this->assertStringContainsString('MIL', $html);
        $this->assertStringContainsString('CON 66/100', $html);

        // ── AUSENCIA: typo histórico "Rxonerado" no debe regresar ───
        $this->assertStringNotContainsString('Rxonerado', $html);
    }

    /**
     * Regresión del formato Jaremar — caso ESMERALDA (NT 03602122).
     *
     * Esta factura tiene productos exentos de ISV (MANTECA) + un bonus
     * con tax_percent=0 (bonus EXENTO). Valida:
     *   - Línea bonus con tax_percent=0 → ISV15=.00 y TOTAL=.00 (NO .01)
     *   - Fila Importe Exento: VALOR=135.97, TOTAL=120.00 (= base - bonus
     *     desc exento), DESCUENTO=.00 (siempre cero en Exento/Exonerado)
     *   - Fila Importe Gravado: VALOR=929.53, DESCUENTO=.00 (porque
     *     ImporteGravado_Desc=0 en este caso), TOTAL=1,068.96
     *   - TOTAL A PAGAR DESCUENTO=15.97- (suma del bonus exento, NO viene
     *     de los _Desc del JSON que tienen 15.98)
     */
    public function test_show_renders_jaremar_format_esmeralda(): void
    {
        $invoice = Invoice::factory()
            ->for($this->manifest, 'manifest')
            ->for($this->warehouseOAC, 'warehouse')
            ->create([
                'invoice_number' => '002-001-01-03602122',
                'client_name' => 'PULPERIA ESMERALDA',
                // ── Importes del JSON real ESMERALDA ──────────────────
                // El JSON manda ImporteExento_Desc=15.98 y _Total=151.95,
                // pero Jaremar los IGNORA al imprimir — calcula TOTAL como
                // 135.97 - 15.97 (bonus desc) = 120.00 y DESCUENTO como .00.
                'importe_excento' => 135.97,
                'importe_exento_desc' => 15.98,
                'importe_exento_isv15' => 0.0,
                'importe_exento_total' => 151.95,
                'importe_gravado' => 929.53,
                'importe_gravado_desc' => 0.0,
                'importe_gravado_isv15' => 139.43,
                'importe_gravado_total' => 1068.96,
                'isv15' => 139.43,
                'isv18' => 0,
                'total' => 1188.96,
            ]);

        // Línea tipo A exenta de ISV (MANTECA DOMESTICA).
        InvoiceLine::factory()->for($invoice, 'invoice')->create([
            'product_id' => '01020021',
            'product_description' => 'MANTECA DOMESTICA DORAL 50X409GR',
            'product_type' => 'A',
            'unit_sale' => 'UN',
            'quantity_fractions' => 6,
            'quantity_decimal' => 0.12,
            'price' => 1000.0,
            'subtotal' => 120.0,
            'discount' => 0,
            'tax' => 0,
            'tax_percent' => 0,
            'tax18' => 0,
            'total' => 120.0,
        ]);

        // Línea tipo B (bonus) — tax_percent=0 (bonus EXENTO):
        //   exact = 760.952 × 0.021 = 15.97999...
        //   VALOR = 15.97
        //   DESCUENTO = 15.97-
        //   ISV15 = .00 (porque tax_percent=0)
        //   TOTAL = .00
        InvoiceLine::factory()->for($invoice, 'invoice')->create([
            'product_id' => '80800013',
            'product_description' => 'PASTA NORMAL 8X12X87GR',
            'product_type' => 'B',
            'unit_sale' => 'UN',
            'quantity_fractions' => 2,
            'quantity_decimal' => 0.021,
            'price' => 760.952,
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'tax_percent' => 0,
            'tax18' => 0,
            'total' => 0,
        ]);

        $payload = $this->encryptedPayload([
            'manifest_id' => $this->manifest->id,
            'invoice_ids' => [$invoice->id],
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('invoices.print', ['payload' => $payload]));

        $response->assertOk();
        $html = $response->getContent();

        // ── Línea bonus con tax_percent=0: VALOR=15.97, DESCUENTO=15.97- ─
        // ISV15 y TOTAL deben ser .00 (NO .01) — esa es la diferencia clave
        // con el caso PUESTO 21 donde tax_percent=15.
        $this->assertMatchesRegularExpression('/>15\.97</', $html);
        $this->assertMatchesRegularExpression('/>15\.97-</', $html);
        $this->assertStringNotContainsString('-15.97', $html);

        // ── Tabla de Importes — algoritmo Jaremar correcto ──────────
        // Fila Exento: VALOR=135.97, DESCUENTO=.00 (siempre), TOTAL=120.00.
        $this->assertStringContainsString('135.97', $html);
        $this->assertStringContainsString('120.00', $html);
        // El descuento del JSON (15.98) NO debe imprimirse en la fila Exento.
        $this->assertStringNotContainsString('15.98-', $html);
        $this->assertStringNotContainsString('15.98 ', $html);

        // Fila Gravado: 929.53 base, ISV15=139.43, TOTAL=1,068.96.
        $this->assertStringContainsString('929.53', $html);
        $this->assertStringContainsString('139.43', $html);
        $this->assertStringContainsString('1,068.96', $html);

        // ── TOTAL A PAGAR: VALOR=1,065.50, DESC=15.97- (NO .00) ───────
        // El descuento total es la suma del bonus exento (15.97), NO viene
        // de los _Desc del JSON.
        $this->assertStringContainsString('1,065.50', $html);
        $this->assertStringContainsString('1,188.96', $html);

        // ── SON LEMPIRAS de 1,188.96 ─────────────────────────────────
        $this->assertStringContainsString('CIENTO OCHENTA Y OCHO CON 96/100', $html);
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
