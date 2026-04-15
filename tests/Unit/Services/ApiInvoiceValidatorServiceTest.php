<?php

namespace Tests\Unit\Services;

use App\Services\ApiInvoiceValidatorService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitarios puros (sin bootstrap de Laravel) del validator que usa el
 * endpoint POST /api/v1/facturas/insertar para sanitizar los payloads que
 * manda el OPL de Jaremar.
 *
 * Si el validator se equivoca, o dejamos entrar basura (NULL → constraint
 * violation en BD), o rechazamos payloads legítimos (Jaremar nos reporta
 * "rechazos" pero realmente eran facturas válidas → se pierden ventas).
 *
 * Por eso cubrimos exhaustivamente:
 *   - batch vacío
 *   - campos requeridos de factura ausentes / null / '' / 0 / '0'
 *   - Total no numérico, negativo, cero (válido para canceladas)
 *   - LineasFactura ausente, null, vacío, con elementos no-array
 *   - campos requeridos de línea
 *   - facturas múltiples donde una es válida y otra no
 *
 * El validator NO debe tirar warnings de PHP 8+ ("Undefined array key")
 * cuando el payload está mal formado — debe devolver `false` y acumular
 * los errores en `$errors` sin convertir warnings en excepciones.
 */
class ApiInvoiceValidatorServiceTest extends TestCase
{
    private ApiInvoiceValidatorService $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ApiInvoiceValidatorService;
    }

    /**
     * Factory helper: genera una factura Jaremar mínima pero COMPLETA
     * (todos los campos requeridos presentes) para que los tests sólo
     * tengan que mutar lo que quieren probar.
     */
    private function validInvoice(array $overrides = []): array
    {
        return array_merge([
            'Nfactura' => 'F0001',
            'NumeroManifiesto' => 'MAN001',
            'Total' => 123.45,
            'LineasFactura' => [$this->validLine()],
            'FechaFactura' => '2026-04-10',
            'Almacen' => 'OAC',
            'Vendedorid' => 'V01',
            'Clienteid' => 'C100',
            'Cliente' => 'Cliente S.A.',
        ], $overrides);
    }

    private function validLine(array $overrides = []): array
    {
        return array_merge([
            'ProductoId' => 'P001',
            'ProductoDesc' => 'Producto test',
            'Total' => 50.0,
            'NumeroLinea' => 1,
        ], $overrides);
    }

    // ── Batch-level ────────────────────────────────────────────────────

    public function test_empty_batch_is_rejected(): void
    {
        $this->assertFalse($this->validator->validate([]));
        $this->assertStringContainsString('vacío', $this->validator->getFirstError());
    }

    public function test_valid_single_invoice_passes(): void
    {
        $this->assertTrue($this->validator->validate([$this->validInvoice()]));
        $this->assertSame([], $this->validator->getErrors());
    }

    public function test_valid_multiple_invoices_pass(): void
    {
        $payload = [
            $this->validInvoice(['Nfactura' => 'F0001']),
            $this->validInvoice(['Nfactura' => 'F0002', 'NumeroManifiesto' => 'MAN002']),
            $this->validInvoice(['Nfactura' => 'F0003', 'Almacen' => 'OAS']),
        ];

        $this->assertTrue($this->validator->validate($payload));
    }

    // ── Campos requeridos ausentes ─────────────────────────────────────

    public function test_missing_nfactura_is_rejected_without_warning(): void
    {
        $invoice = $this->validInvoice();
        unset($invoice['Nfactura']);

        // Clave importante: esto NO debe tirar "Undefined array key 'Nfactura'".
        $this->assertFalse($this->validator->validate([$invoice]));
        $this->assertStringContainsString("'Nfactura'", $this->validator->getFirstError());
    }

    public function test_missing_all_required_fields_reports_all_of_them(): void
    {
        // Factura totalmente vacía → debe reportar los 9 campos faltantes.
        $this->assertFalse($this->validator->validate([[]]));

        $errors = $this->validator->getErrors();

        foreach (['Nfactura', 'NumeroManifiesto', 'Total', 'LineasFactura', 'FechaFactura', 'Almacen', 'Vendedorid', 'Clienteid', 'Cliente'] as $field) {
            $this->assertTrue(
                $this->errorsContain($errors, "'{$field}'"),
                "Se esperaba error para el campo '{$field}' pero no se encontró. Errores: ".implode(' | ', $errors)
            );
        }
    }

    public function test_null_values_are_treated_as_missing(): void
    {
        // Campos presentes pero null → también deben rechazarse.
        $invoice = $this->validInvoice([
            'Nfactura' => null,
            'NumeroManifiesto' => null,
            'LineasFactura' => null,
        ]);

        $this->assertFalse($this->validator->validate([$invoice]));
        $errors = $this->validator->getErrors();
        $this->assertTrue($this->errorsContain($errors, "'Nfactura'"));
        $this->assertTrue($this->errorsContain($errors, "'NumeroManifiesto'"));
        $this->assertTrue($this->errorsContain($errors, "'LineasFactura'"));
    }

    public function test_empty_string_values_are_treated_as_missing(): void
    {
        $invoice = $this->validInvoice([
            'Nfactura' => '',
            'Cliente' => '',
            'FechaFactura' => '',
        ]);

        $this->assertFalse($this->validator->validate([$invoice]));
        $errors = $this->validator->getErrors();
        $this->assertTrue($this->errorsContain($errors, "'Nfactura'"));
        $this->assertTrue($this->errorsContain($errors, "'Cliente'"));
        $this->assertTrue($this->errorsContain($errors, "'FechaFactura'"));
    }

    // ── Casos especiales del campo Total ───────────────────────────────

    public function test_total_zero_as_int_is_accepted(): void
    {
        // Total = 0 debe ser aceptado porque facturas canceladas / de
        // ajuste pueden llegar con total cero. El validator distingue 0
        // de null para no perderlas.
        $invoice = $this->validInvoice(['Total' => 0]);

        $this->assertTrue($this->validator->validate([$invoice]));
    }

    public function test_total_zero_as_string_is_accepted(): void
    {
        $invoice = $this->validInvoice(['Total' => '0']);

        $this->assertTrue($this->validator->validate([$invoice]));
    }

    public function test_total_negative_is_rejected(): void
    {
        $invoice = $this->validInvoice(['Total' => -50]);

        $this->assertFalse($this->validator->validate([$invoice]));
        $this->assertStringContainsString('positivo', $this->validator->getFirstError());
    }

    public function test_total_non_numeric_string_is_rejected(): void
    {
        $invoice = $this->validInvoice(['Total' => 'abc']);

        $this->assertFalse($this->validator->validate([$invoice]));
        $this->assertStringContainsString('positivo', $this->validator->getFirstError());
    }

    public function test_total_as_numeric_string_is_accepted(): void
    {
        // Jaremar a veces manda Total como string: "450.00".
        $invoice = $this->validInvoice(['Total' => '450.00']);

        $this->assertTrue($this->validator->validate([$invoice]));
    }

    // ── LineasFactura ──────────────────────────────────────────────────

    public function test_lineas_factura_as_empty_array_is_rejected(): void
    {
        $invoice = $this->validInvoice(['LineasFactura' => []]);

        $this->assertFalse($this->validator->validate([$invoice]));
        $this->assertTrue(
            $this->errorsContain($this->validator->getErrors(), 'LineasFactura')
        );
    }

    public function test_lineas_factura_with_non_array_element_is_rejected_without_warning(): void
    {
        // Un elemento escalar dentro del array de líneas no debe
        // romper el validator con "Argument must be of type array".
        $invoice = $this->validInvoice(['LineasFactura' => ['string-inválido']]);

        $this->assertFalse($this->validator->validate([$invoice]));
        $this->assertTrue(
            $this->errorsContain($this->validator->getErrors(), 'inválido')
        );
    }

    public function test_line_missing_required_field_is_rejected(): void
    {
        $badLine = $this->validLine();
        unset($badLine['ProductoId']);

        $invoice = $this->validInvoice(['LineasFactura' => [$badLine]]);

        $this->assertFalse($this->validator->validate([$invoice]));
        $this->assertStringContainsString("'ProductoId'", $this->validator->getFirstError());
    }

    public function test_line_numero_linea_zero_is_accepted(): void
    {
        // NumeroLinea = 0 es legítimo (isset lo acepta, empty no).
        $line = $this->validLine(['NumeroLinea' => 0]);
        $invoice = $this->validInvoice(['LineasFactura' => [$line]]);

        $this->assertTrue($this->validator->validate([$invoice]));
    }

    // ── Escenario mixto: válidas + inválidas en el mismo batch ─────────

    public function test_mixed_batch_reports_errors_with_correct_positions(): void
    {
        $valid = $this->validInvoice(['Nfactura' => 'F0001']);
        $bad = $this->validInvoice(['Nfactura' => 'F0002', 'Total' => -10]);
        $veryBad = []; // factura totalmente vacía

        $this->assertFalse($this->validator->validate([$valid, $bad, $veryBad]));

        $errors = $this->validator->getErrors();

        // La factura mala #2 debe referenciar 'F0002' en el error de Total
        $this->assertTrue($this->errorsContain($errors, 'F0002'));

        // La factura #3 (vacía) debe referenciarse por posición
        $this->assertTrue($this->errorsContain($errors, '#3'));

        // La factura válida no debe generar ningún error referenciándola
        $this->assertFalse($this->errorsContain($errors, 'F0001'));
    }

    // ── API de getErrors / getFirstError ───────────────────────────────

    public function test_get_first_error_returns_default_when_no_errors(): void
    {
        $this->validator->validate([$this->validInvoice()]);

        // Sin errores, getFirstError devuelve el default (no rompe).
        $this->assertSame('Error desconocido.', $this->validator->getFirstError());
    }

    public function test_successive_validate_calls_reset_errors(): void
    {
        $this->validator->validate([]); // llena errores
        $this->assertNotEmpty($this->validator->getErrors());

        $this->validator->validate([$this->validInvoice()]); // debe limpiar
        $this->assertSame([], $this->validator->getErrors());
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Búsqueda case-sensitive de un substring en el array de errores.
     * Usamos helpers nativos (no collect()) para mantener este test
     * totalmente libre de dependencias de Laravel.
     */
    private function errorsContain(array $errors, string $needle): bool
    {
        foreach ($errors as $error) {
            if (str_contains($error, $needle)) {
                return true;
            }
        }

        return false;
    }
}
