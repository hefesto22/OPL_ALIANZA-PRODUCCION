<?php

namespace Tests\Unit\Helpers;

use App\Helpers\NumberHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests del formato AS400/COBOL que usa Jaremar en sus facturas.
 *
 * Cada aserción corresponde a un patrón observado en facturas físicas
 * de Jaremar. Si Jaremar cambia su formato, estos tests fallan primero —
 * son la red de seguridad que evita una regresión silenciosa del PDF.
 *
 * Ver memory project_invoice_pdf_jaremar_format.
 */
class NumberHelperTest extends TestCase
{
    public function test_as400_zero_value_drops_leading_digit_with_two_decimals(): void
    {
        // Caso: VALOR=0 en factura Jaremar imprime ".00", no "0.00".
        $this->assertSame('.00', NumberHelper::as400(0.0));
        $this->assertSame('.00', NumberHelper::as400(0));
    }

    public function test_as400_zero_value_respects_decimals_parameter(): void
    {
        // Cantidad cero con 3 decimales imprime ".000".
        $this->assertSame('.000', NumberHelper::as400(0.0, 3));
    }

    public function test_as400_value_below_one_drops_leading_zero(): void
    {
        // Caso: CANT=.250 (cantidad fracción) imprime sin "0" delante.
        $this->assertSame('.250', NumberHelper::as400(0.250, 3));
        $this->assertSame('.021', NumberHelper::as400(0.021, 3));
        $this->assertSame('.50', NumberHelper::as400(0.5));
    }

    public function test_as400_value_equal_or_above_one_keeps_leading_digit(): void
    {
        // Caso: CANT=1.000, PRECIO=180.000 imprimen formato estándar.
        $this->assertSame('1.000', NumberHelper::as400(1.0, 3));
        $this->assertSame('180.000', NumberHelper::as400(180.0, 3));
        $this->assertSame('27.00', NumberHelper::as400(27.0));
    }

    public function test_as400_thousands_separator_uses_comma(): void
    {
        // Caso: PRECIO=1,000.000 con separador de miles.
        $this->assertSame('1,000.000', NumberHelper::as400(1000.0, 3));
        $this->assertSame('1,234.56', NumberHelper::as400(1234.56));
    }

    public function test_as400_negative_value_uses_trailing_minus_sign(): void
    {
        // Caso clave: descuento -15.97 imprime "15.97-" (signo trailing
        // como AS400/COBOL), no "-15.97".
        $this->assertSame('15.97-', NumberHelper::as400(-15.97));
        $this->assertSame('31.95-', NumberHelper::as400(-31.95));
    }

    public function test_as400_negative_value_below_one_drops_leading_zero(): void
    {
        // Combinación: |valor| < 1 y negativo → ".25-" (sin cero, signo trailing).
        $this->assertSame('.25-', NumberHelper::as400(-0.25));
    }

    public function test_as400_rounds_to_requested_decimals(): void
    {
        // El helper redondea antes de formatear para evitar surprises.
        $this->assertSame('15.98', NumberHelper::as400(15.984));
        $this->assertSame('15.99', NumberHelper::as400(15.986));
    }

    public function test_as400_value_that_rounds_to_zero_is_displayed_as_zero(): void
    {
        // Pequeño positivo que redondea a cero NO debe imprimir un valor —
        // imprime ".00" como cualquier cero.
        $this->assertSame('.00', NumberHelper::as400(0.001));
        $this->assertSame('.00', NumberHelper::as400(-0.001));
    }
}
