<?php

namespace Tests\Unit\Services;

use App\Services\JsonValidatorService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests puros para JsonValidatorService.
 *
 * Este servicio valida el JSON que sube el operador vía Filament
 * antes de disparar el Job de importación. Si deja pasar JSON malo,
 * el Job falla silenciosamente y el operador no sabe qué pasó.
 *
 * No necesita BD excepto isDuplicate() que se testea en Feature.
 */
class JsonValidatorServiceTest extends TestCase
{
    private function makeValidator(): JsonValidatorService
    {
        // isDuplicate() usa Manifest::where que necesita BD.
        // Para los tests puros lo evitamos — solo testeamos validate().
        return new JsonValidatorService();
    }

    private function validInvoice(array $overrides = []): array
    {
        return array_merge([
            'Nfactura'         => 'F-001',
            'NumeroManifiesto' => 'MAN-001',
            'Total'            => 500.0,
            'LineasFactura'    => [[
                'ProductoId'   => 'ART-001',
                'ProductoDesc' => 'PRODUCTO',
                'Total'        => 500.0,
                'NumeroLinea'  => 1,
            ]],
            'FechaFactura'     => '2026-04-10',
            'Almacen'          => 'OAC',
            'Vendedorid'       => 'V01',
            'Clienteid'        => 'C001',
            'Cliente'          => 'PULPERIA PRUEBA',
        ], $overrides);
    }

    // ═══════════════════════════════════════════════════════════════
    //  JSON structure
    // ═══════════════════════════════════════════════════════════════

    public function test_invalid_json_string_returns_false(): void
    {
        $v = $this->makeValidator();
        $this->assertFalse($v->validate('not json at all'));
        $this->assertNotEmpty($v->getErrors());
        $this->assertStringContainsString('JSON válido', $v->getFirstError());
    }

    public function test_empty_array_returns_false(): void
    {
        $v = $this->makeValidator();
        $this->assertFalse($v->validate('[]'));
        $this->assertStringContainsString('vacío', $v->getFirstError());
    }

    public function test_non_array_json_returns_false(): void
    {
        $v = $this->makeValidator();
        $this->assertFalse($v->validate('"just a string"'));
    }

    // ═══════════════════════════════════════════════════════════════
    //  Manifest number consistency
    // ═══════════════════════════════════════════════════════════════

    public function test_multiple_manifest_numbers_returns_false(): void
    {
        $inv1 = $this->validInvoice(['NumeroManifiesto' => 'MAN-001']);
        $inv2 = $this->validInvoice(['NumeroManifiesto' => 'MAN-002', 'Nfactura' => 'F-002']);
        $json = json_encode([$inv1, $inv2]);

        $v = $this->makeValidator();
        $this->assertFalse($v->validate($json));
        $this->assertStringContainsString('múltiples manifiestos', $v->getFirstError());
    }

    // ═══════════════════════════════════════════════════════════════
    //  Invoice field validation
    // ═══════════════════════════════════════════════════════════════

    public function test_valid_single_invoice_passes(): void
    {
        $json = json_encode([$this->validInvoice()]);
        $v = $this->makeValidator();
        $this->assertTrue($v->validate($json));
        $this->assertEmpty($v->getErrors());
    }

    public function test_missing_required_invoice_field_fails(): void
    {
        $inv = $this->validInvoice(['Nfactura' => '']);
        $json = json_encode([$inv]);

        $v = $this->makeValidator();
        $this->assertFalse($v->validate($json));
        $this->assertStringContainsString('Nfactura', $v->getFirstError());
    }

    public function test_null_nfactura_does_not_throw_type_error(): void
    {
        // Antes del fix, unset(Nfactura) causaba TypeError en validateLine()
        // porque recibía null en un parámetro tipado como string.
        $inv = $this->validInvoice();
        unset($inv['Nfactura']);
        $json = json_encode([$inv]);

        $v = $this->makeValidator();
        $this->assertFalse($v->validate($json));
        $this->assertStringContainsString('Nfactura', $v->getFirstError());
    }

    public function test_negative_total_fails(): void
    {
        $json = json_encode([$this->validInvoice(['Total' => -100])]);
        $v = $this->makeValidator();
        $this->assertFalse($v->validate($json));
        $this->assertStringContainsString('Total', $v->getFirstError());
    }

    public function test_non_numeric_total_fails(): void
    {
        $json = json_encode([$this->validInvoice(['Total' => 'abc'])]);
        $v = $this->makeValidator();
        $this->assertFalse($v->validate($json));
        $this->assertStringContainsString('Total', $v->getFirstError());
    }

    public function test_empty_lineas_factura_fails(): void
    {
        $json = json_encode([$this->validInvoice(['LineasFactura' => []])]);
        $v = $this->makeValidator();
        $this->assertFalse($v->validate($json));
        $this->assertStringContainsString('LineasFactura', $v->getFirstError());
    }

    // ═══════════════════════════════════════════════════════════════
    //  Line field validation
    // ═══════════════════════════════════════════════════════════════

    public function test_missing_required_line_field_fails(): void
    {
        $inv = $this->validInvoice([
            'LineasFactura' => [[
                'ProductoId'   => 'ART-001',
                'ProductoDesc' => 'PROD',
                // missing Total and NumeroLinea
            ]],
        ]);
        $json = json_encode([$inv]);

        $v = $this->makeValidator();
        $this->assertFalse($v->validate($json));

        $errors = $v->getErrors();
        $hasTotal = false;
        $hasNumeroLinea = false;
        foreach ($errors as $err) {
            if (str_contains($err, 'Total')) $hasTotal = true;
            if (str_contains($err, 'NumeroLinea')) $hasNumeroLinea = true;
        }
        $this->assertTrue($hasTotal, 'Should report missing Total in line');
        $this->assertTrue($hasNumeroLinea, 'Should report missing NumeroLinea in line');
    }

    // ═══════════════════════════════════════════════════════════════
    //  Helper methods
    // ═══════════════════════════════════════════════════════════════

    public function test_getManifestNumber_extracts_from_first_element(): void
    {
        $json = json_encode([$this->validInvoice(['NumeroManifiesto' => 'MAN-EXTRACT'])]);
        $v = $this->makeValidator();
        $this->assertSame('MAN-EXTRACT', $v->getManifestNumber($json));
    }

    public function test_countValidWarehouses_counts_only_oac_oas_oao(): void
    {
        $invoices = [
            $this->validInvoice(['Almacen' => 'OAC', 'Nfactura' => 'F-1']),
            $this->validInvoice(['Almacen' => 'OAS', 'Nfactura' => 'F-2']),
            $this->validInvoice(['Almacen' => 'XXX', 'Nfactura' => 'F-3']),
        ];
        $json = json_encode($invoices);

        $v = $this->makeValidator();
        $this->assertSame(2, $v->countValidWarehouses($json));
    }

    public function test_getFirstError_returns_default_on_no_errors(): void
    {
        $v = $this->makeValidator();
        $this->assertSame('Error desconocido.', $v->getFirstError());
    }

    public function test_successive_validate_calls_reset_errors(): void
    {
        $v = $this->makeValidator();

        $v->validate('bad json');
        $this->assertNotEmpty($v->getErrors());

        $v->validate(json_encode([$this->validInvoice()]));
        $this->assertEmpty($v->getErrors());
    }
}
