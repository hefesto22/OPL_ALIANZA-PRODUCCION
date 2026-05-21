<?php

namespace Tests\Unit\Services;

use App\Services\ManifestDateValidator;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Tests del ManifestDateValidator.
 *
 * Cubre las 4 validaciones del contrato (V1 homogeneidad, V2 no futura,
 * V3 no demasiado antigua, V4 derivación de manifests.date) más los
 * helpers de normalización de timezone.
 *
 * Estos tests NO tocan BD — el validator es puro: recibe arrays, retorna
 * arrays. Solo extiende Tests\TestCase para que config('manifests.*')
 * resuelva contra los valores reales del proyecto.
 *
 * Carbon::setTestNow() congela "hoy" en cada test para que los cálculos
 * de daysBackdated sean deterministas. Sin esto, el test que corre a las
 * 23:59 daría resultados distintos del que corre a las 00:01.
 */
class ManifestDateValidatorTest extends TestCase
{
    private ManifestDateValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        // Congelar "hoy" en una fecha fija TZ Honduras.
        // 2026-05-20 18:00 Tegucigalpa = 2026-05-21 00:00 UTC.
        // Elegimos la tarde para que cualquier conversión incorrecta de TZ
        // delate el bug (caería al día siguiente en UTC).
        Carbon::setTestNow(
            Carbon::create(2026, 5, 20, 18, 0, 0, 'America/Tegucigalpa')
        );

        $this->validator = new ManifestDateValidator;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $manifest, string $fecha, string $factura = 'F-001'): array
    {
        return [
            'NumeroManifiesto' => $manifest,
            'FechaFactura' => $fecha,
            'Nfactura' => $factura,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  normalizeToDate — parsea formatos de Jaremar
    // ═══════════════════════════════════════════════════════════════════

    public function test_normalize_handles_utc_midnight_z_without_timezone_shift(): void
    {
        // Patrón típico de Jaremar: "T00:00:00.000Z" significa "este día
        // calendario", NO "medianoche UTC que cae a las 18:00 del día
        // anterior en Honduras". Tomamos la parte de fecha sin convertir.
        $result = $this->validator->normalizeToDate('2026-03-22T00:00:00.000Z');
        $this->assertSame('2026-03-22', $result);
    }

    public function test_normalize_handles_utc_midnight_z_without_milliseconds(): void
    {
        $result = $this->validator->normalizeToDate('2026-03-22T00:00:00Z');
        $this->assertSame('2026-03-22', $result);
    }

    public function test_normalize_handles_latin_american_slash_format(): void
    {
        // Jaremar a veces manda "dd/mm/yyyy" desde su backoffice.
        // PHP interpretaría "/" como m/d/Y (americano) y romperia.
        $result = $this->validator->normalizeToDate('22/03/2026');
        $this->assertSame('2026-03-22', $result);
    }

    public function test_normalize_handles_plain_iso_date(): void
    {
        $result = $this->validator->normalizeToDate('2026-03-22');
        $this->assertSame('2026-03-22', $result);
    }

    public function test_normalize_converts_real_utc_time_to_honduras_tz(): void
    {
        // "2026-03-22T03:00:00Z" = 21:00 del día anterior en Honduras.
        // Acá SÍ aplicamos conversión TZ porque la hora no es 00:00:00
        // (no es el patrón "fecha plana disfrazada de timestamp").
        $result = $this->validator->normalizeToDate('2026-03-22T03:00:00Z');
        $this->assertSame('2026-03-21', $result);
    }

    public function test_normalize_returns_null_for_empty_or_invalid_input(): void
    {
        $this->assertNull($this->validator->normalizeToDate(null));
        $this->assertNull($this->validator->normalizeToDate(''));
        $this->assertNull($this->validator->normalizeToDate('no es una fecha'));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  V1 — findMixedDates
    // ═══════════════════════════════════════════════════════════════════

    public function test_find_mixed_dates_returns_empty_when_all_same_date(): void
    {
        $invoices = [
            $this->invoice('M1', '2026-05-20', 'F-001'),
            $this->invoice('M1', '2026-05-20', 'F-002'),
            $this->invoice('M1', '2026-05-20', 'F-003'),
        ];

        $this->assertSame([], $this->validator->findMixedDates($invoices));
    }

    public function test_find_mixed_dates_detects_two_distinct_dates(): void
    {
        $invoices = [
            $this->invoice('M1', '2026-05-20', 'F-001'),
            $this->invoice('M1', '2026-05-21', 'F-002'),
            $this->invoice('M1', '2026-05-20', 'F-003'),
        ];

        $mixed = $this->validator->findMixedDates($invoices);

        $this->assertCount(2, $mixed);
        $this->assertArrayHasKey('2026-05-20', $mixed);
        $this->assertArrayHasKey('2026-05-21', $mixed);
        $this->assertSame(['F-001', 'F-003'], $mixed['2026-05-20']);
        $this->assertSame(['F-002'], $mixed['2026-05-21']);
    }

    public function test_find_mixed_dates_handles_single_invoice(): void
    {
        $invoices = [$this->invoice('M1', '2026-05-20')];
        $this->assertSame([], $this->validator->findMixedDates($invoices));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  V4 — resolveManifestDate
    // ═══════════════════════════════════════════════════════════════════

    public function test_resolve_manifest_date_returns_unique_date_for_homogeneous_group(): void
    {
        $invoices = [
            $this->invoice('M1', '2026-05-15'),
            $this->invoice('M1', '2026-05-15'),
        ];

        $this->assertSame('2026-05-15', $this->validator->resolveManifestDate($invoices));
    }

    public function test_resolve_manifest_date_returns_max_as_safe_fallback_when_mixed(): void
    {
        // Caso defensivo: si por algún motivo llega un grupo mezclado
        // (config reject_mixed_dates=false), tomamos la fecha MÁS RECIENTE
        // como aproximación más segura — representa mejor "trabajo del día".
        $invoices = [
            $this->invoice('M1', '2026-05-10'),
            $this->invoice('M1', '2026-05-20'),
            $this->invoice('M1', '2026-05-15'),
        ];

        $this->assertSame('2026-05-20', $this->validator->resolveManifestDate($invoices));
    }

    public function test_resolve_manifest_date_returns_null_for_empty_invoices(): void
    {
        $this->assertNull($this->validator->resolveManifestDate([]));
    }

    public function test_resolve_manifest_date_ignores_unparseable_dates(): void
    {
        $invoices = [
            $this->invoice('M1', 'fecha basura'),
            $this->invoice('M1', '2026-05-20'),
        ];

        $this->assertSame('2026-05-20', $this->validator->resolveManifestDate($invoices));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  validateBatch — pipeline completo (V1 + V2 + V3)
    // ═══════════════════════════════════════════════════════════════════

    public function test_validate_batch_accepts_valid_same_day_manifest(): void
    {
        $invoices = [
            $this->invoice('M001', '2026-05-20', 'F-001'),
            $this->invoice('M001', '2026-05-20', 'F-002'),
        ];

        $result = $this->validator->validateBatch($invoices);

        $this->assertFalse($result['has_errors']);
        $this->assertEmpty($result['invalid_manifests']);
        $this->assertCount(1, $result['valid_manifests']);
        $this->assertSame('M001', $result['valid_manifests'][0]['manifiesto']);
        $this->assertSame('2026-05-20', $result['valid_manifests'][0]['fecha_operativa']);
        $this->assertSame(2, $result['valid_manifests'][0]['total_facturas']);
    }

    public function test_validate_batch_accepts_retroactive_within_threshold(): void
    {
        // Hoy es 2026-05-20. Hace 7 días = 2026-05-13. Dentro del límite
        // por default (30 días) → debe aceptarse.
        $invoices = [
            $this->invoice('M001', '2026-05-13', 'F-001'),
        ];

        $result = $this->validator->validateBatch($invoices);

        $this->assertFalse($result['has_errors']);
        $this->assertCount(1, $result['valid_manifests']);
        $this->assertSame('2026-05-13', $result['valid_manifests'][0]['fecha_operativa']);
    }

    public function test_validate_batch_accepts_exactly_at_backdate_limit(): void
    {
        // Hoy = 2026-05-20. Hace exactamente 30 días = 2026-04-20.
        // Debe aceptarse (>= no es >).
        $invoices = [
            $this->invoice('M001', '2026-04-20', 'F-001'),
        ];

        $result = $this->validator->validateBatch($invoices);

        $this->assertFalse($result['has_errors']);
    }

    public function test_validate_batch_rejects_mixed_dates_within_same_manifest(): void
    {
        $invoices = [
            $this->invoice('M001', '2026-05-20', 'F-001'),
            $this->invoice('M001', '2026-05-19', 'F-002'),
        ];

        $result = $this->validator->validateBatch($invoices);

        $this->assertTrue($result['has_errors']);
        $this->assertCount(1, $result['invalid_manifests']);

        $invalid = $result['invalid_manifests'][0];
        $this->assertSame('M001', $invalid['manifiesto']);
        $this->assertSame('FECHAS_MEZCLADAS', $invalid['motivo']);
        $this->assertContains('2026-05-20', $invalid['detalle']['fechas_encontradas']);
        $this->assertContains('2026-05-19', $invalid['detalle']['fechas_encontradas']);
    }

    public function test_validate_batch_rejects_future_dated_manifest(): void
    {
        $invoices = [
            // Hoy = 2026-05-20. Mañana = 2026-05-21 → futuro.
            $this->invoice('M001', '2026-05-21', 'F-001'),
        ];

        $result = $this->validator->validateBatch($invoices);

        $this->assertTrue($result['has_errors']);
        $this->assertSame('FECHA_FACTURA_FUTURA', $result['invalid_manifests'][0]['motivo']);
        $this->assertSame('2026-05-21', $result['invalid_manifests'][0]['detalle']['fecha_factura']);
        $this->assertSame('2026-05-20', $result['invalid_manifests'][0]['detalle']['hoy_servidor']);
    }

    public function test_validate_batch_rejects_manifest_older_than_backdate_limit(): void
    {
        // Hoy = 2026-05-20. Hace 45 días = 2026-04-05. Supera 30 → rechazo.
        $invoices = [
            $this->invoice('M001', '2026-04-05', 'F-001'),
        ];

        $result = $this->validator->validateBatch($invoices);

        $this->assertTrue($result['has_errors']);
        $this->assertSame('FECHA_FACTURA_DEMASIADO_ANTIGUA', $result['invalid_manifests'][0]['motivo']);
        $this->assertSame(45, $result['invalid_manifests'][0]['detalle']['dias_antiguedad']);
        $this->assertSame(30, $result['invalid_manifests'][0]['detalle']['limite_dias']);
    }

    public function test_validate_batch_reports_each_invalid_manifest_separately(): void
    {
        // Batch mixto: M001 mezclado, M002 futuro, M003 válido.
        $invoices = [
            $this->invoice('M001', '2026-05-20', 'F-001'),
            $this->invoice('M001', '2026-05-19', 'F-002'),

            $this->invoice('M002', '2026-05-25', 'F-003'),

            $this->invoice('M003', '2026-05-20', 'F-004'),
        ];

        $result = $this->validator->validateBatch($invoices);

        $this->assertTrue($result['has_errors']);
        $this->assertCount(2, $result['invalid_manifests']);
        $this->assertCount(1, $result['valid_manifests']);

        $motivos = array_column($result['invalid_manifests'], 'motivo');
        $this->assertContains('FECHAS_MEZCLADAS', $motivos);
        $this->assertContains('FECHA_FACTURA_FUTURA', $motivos);
        $this->assertSame('M003', $result['valid_manifests'][0]['manifiesto']);
    }

    public function test_validate_batch_respects_allow_future_config_when_true(): void
    {
        config(['manifests.dates.allow_future' => true]);

        $invoices = [$this->invoice('M001', '2026-05-25', 'F-001')];

        $result = $this->validator->validateBatch($invoices);

        $this->assertFalse($result['has_errors']);
    }

    public function test_validate_batch_respects_custom_max_backdate_days(): void
    {
        // Bajar el límite a 7 días.
        config(['manifests.dates.max_backdate_days' => 7]);

        // Hoy = 2026-05-20. Hace 10 días = 2026-05-10. Excede 7 → rechazo.
        $invoices = [$this->invoice('M001', '2026-05-10', 'F-001')];

        $result = $this->validator->validateBatch($invoices);

        $this->assertTrue($result['has_errors']);
        $this->assertSame('FECHA_FACTURA_DEMASIADO_ANTIGUA', $result['invalid_manifests'][0]['motivo']);
        $this->assertSame(7, $result['invalid_manifests'][0]['detalle']['limite_dias']);
    }

    public function test_validate_batch_respects_reject_mixed_dates_disabled(): void
    {
        // Si desactivamos la regla de Isac, las mezclas se aceptan.
        config(['manifests.dates.reject_mixed_dates' => false]);

        $invoices = [
            $this->invoice('M001', '2026-05-20', 'F-001'),
            $this->invoice('M001', '2026-05-19', 'F-002'),
        ];

        $result = $this->validator->validateBatch($invoices);

        $this->assertFalse($result['has_errors']);
        // Con mezcla aceptada, V4 usa MAX como fallback → fecha más reciente.
        $this->assertSame('2026-05-20', $result['valid_manifests'][0]['fecha_operativa']);
    }

    public function test_validate_batch_handles_empty_input_without_error(): void
    {
        $result = $this->validator->validateBatch([]);

        $this->assertFalse($result['has_errors']);
        $this->assertEmpty($result['invalid_manifests']);
        $this->assertEmpty($result['valid_manifests']);
    }

    public function test_validate_batch_reports_unparseable_fecha_as_invalid(): void
    {
        $invoices = [
            // FechaFactura no parseable + sin mezcla (una sola factura).
            // No debe disparar V1 (mixed) pero sí caer en FECHA_FACTURA_INVALIDA.
            ['NumeroManifiesto' => 'M001', 'FechaFactura' => 'fecha basura', 'Nfactura' => 'F-001'],
        ];

        $result = $this->validator->validateBatch($invoices);

        $this->assertTrue($result['has_errors']);
        $this->assertSame('FECHA_FACTURA_INVALIDA', $result['invalid_manifests'][0]['motivo']);
    }
}
