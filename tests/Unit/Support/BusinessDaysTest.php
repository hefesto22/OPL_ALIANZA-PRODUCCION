<?php

namespace Tests\Unit\Support;

use App\Support\BusinessDays;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Días hábiles de la operación Hosana: lunes a sábado, domingo NO cuenta.
 *
 * Fechas de referencia (julio 2026):
 *   sáb 18 · dom 19 · lun 20 · mar 21 · mié 22 · jue 23 · vie 24 ·
 *   sáb 25 · dom 26 · lun 27 · mar 28 · mié 29 · jue 30
 */
class BusinessDaysTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ejemplo_operativo_viernes_cierra_miercoles(): void
    {
        // El ejemplo exacto de la regla: llega viernes → vie(1), sáb(2),
        // dom no cuenta, lun(3), mar(4), mié(5) → cierra miércoles 11:59 pm.
        $deadline = BusinessDays::deadline('2026-07-24', 5);

        $this->assertSame('2026-07-29 23:59:59', $deadline->format('Y-m-d H:i:s'));
        $this->assertSame('America/Tegucigalpa', $deadline->timezone->getName());
    }

    public function test_semana_sin_domingo_intermedio(): void
    {
        // Llega lunes → lun(1)..vie(5), sin domingo en medio.
        $deadline = BusinessDays::deadline('2026-07-20', 5);

        $this->assertSame('2026-07-24 23:59:59', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_llegada_en_sabado_salta_el_domingo(): void
    {
        // sáb(1), dom no cuenta, lun(2), mar(3), mié(4), jue(5).
        $deadline = BusinessDays::deadline('2026-07-18', 5);

        $this->assertSame('2026-07-23 23:59:59', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_llegada_en_domingo_arranca_el_lunes(): void
    {
        // El domingo no se trabaja: día 1 = lunes → lun(1)..vie(5).
        $deadline = BusinessDays::deadline('2026-07-19', 5);

        $this->assertSame('2026-07-24 23:59:59', $deadline->format('Y-m-d H:i:s'));
    }

    public function test_remaining_cuenta_hoy_y_excluye_domingos(): void
    {
        $deadline = BusinessDays::deadline('2026-07-24', 5); // mié 29 fin de día

        // Lunes 27 por la mañana (HN): lun(1), mar(2), mié(3) → 3 restantes.
        Carbon::setTestNow(Carbon::parse('2026-07-27 10:00:00', 'America/Tegucigalpa'));
        $this->assertSame(3, BusinessDays::remaining($deadline));

        // El propio día del cierre → 1 ("Hoy 11:59 pm").
        Carbon::setTestNow(Carbon::parse('2026-07-29 08:00:00', 'America/Tegucigalpa'));
        $this->assertSame(1, BusinessDays::remaining($deadline));

        // Después del cierre → 0.
        Carbon::setTestNow(Carbon::parse('2026-07-30 08:00:00', 'America/Tegucigalpa'));
        $this->assertSame(0, BusinessDays::remaining($deadline));
    }
}
