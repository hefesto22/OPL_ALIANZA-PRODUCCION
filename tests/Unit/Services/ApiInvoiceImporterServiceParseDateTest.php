<?php

namespace Tests\Unit\Services;

use App\Services\ApiInvoiceImporterService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests unitarios puros (sin bootstrap de Laravel) sobre parseDate().
 *
 * parseDate() es una de las funciones más críticas del importador porque
 * Jaremar envía fechas en varios formatos mezclados dentro del mismo payload:
 *   - ISO con zona (2025-12-28T00:00:00.000Z)
 *   - ISO sin zona (2025-12-28T00:00:00)
 *   - SQL (2025-12-28)
 *   - Latinoamericano (20/03/2026)
 *
 * Si parseDate interpreta mal un formato, las fechas quedan desplazadas y
 * los reportes por período fallan silenciosamente. Por eso vale la pena
 * blindarla con tests antes de cualquier refactor futuro.
 */
class ApiInvoiceImporterServiceParseDateTest extends TestCase
{
    /**
     * El constructor real toca la base de datos (Warehouse::pluck), así que
     * instanciamos sin invocar __construct y luego usamos reflection para
     * llamar al método protegido.
     */
    private function invokeParseDate(?string $input): ?string
    {
        $service = (new ReflectionClass(ApiInvoiceImporterService::class))
            ->newInstanceWithoutConstructor();

        $method = (new ReflectionClass(ApiInvoiceImporterService::class))
            ->getMethod('parseDate');
        $method->setAccessible(true);

        return $method->invoke($service, $input);
    }

    public function test_null_returns_null(): void
    {
        $this->assertNull($this->invokeParseDate(null));
    }

    public function test_empty_string_returns_null(): void
    {
        $this->assertNull($this->invokeParseDate(''));
    }

    public function test_latin_american_dd_mm_yyyy_format(): void
    {
        // Formato crítico: "20/03/2026" NO debe leerse como m/d/Y.
        // Si Carbon::parse lo tomara como m/d/Y → mes=20 → error.
        $this->assertSame('2026-03-20', $this->invokeParseDate('20/03/2026'));
    }

    public function test_iso_with_milliseconds_and_zulu(): void
    {
        $this->assertSame('2025-12-28', $this->invokeParseDate('2025-12-28T00:00:00.000Z'));
    }

    public function test_iso_with_zulu(): void
    {
        $this->assertSame('2025-12-28', $this->invokeParseDate('2025-12-28T00:00:00Z'));
    }

    public function test_iso_without_timezone(): void
    {
        $this->assertSame('2025-12-28', $this->invokeParseDate('2025-12-28T00:00:00'));
    }

    public function test_sql_date(): void
    {
        $this->assertSame('2025-12-28', $this->invokeParseDate('2025-12-28'));
    }

    public function test_invalid_string_returns_null_instead_of_throwing(): void
    {
        // Robustez: nunca debe lanzar. Jaremar podría enviar basura y el
        // importador debe poder continuar con el resto del batch.
        $this->assertNull($this->invokeParseDate('not-a-date-at-all'));
    }

    public function test_zero_padded_dates(): void
    {
        $this->assertSame('2026-01-05', $this->invokeParseDate('05/01/2026'));
    }
}
