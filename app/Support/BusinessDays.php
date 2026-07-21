<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Aritmética de días HÁBILES de la operación Hosana: lunes a sábado,
 * el domingo NO cuenta (no se trabaja). Feriados: no contemplados aún —
 * si se necesitan, agregar aquí una lista configurable, no en los callers.
 *
 * Uso principal: la ventana de registro de devoluciones (regla operativa
 * 2026-07-21). Un manifiesto que llega el viernes con ventana de 5 días
 * hábiles cuenta: viernes(1), sábado(2), lunes(3), martes(4), miércoles(5)
 * → cierra el miércoles a las 11:59:59 pm hora Honduras.
 *
 * Todas las fechas se calculan en el timezone operativo
 * (config manifests.dates.timezone) — Honduras no aplica DST.
 */
class BusinessDays
{
    /**
     * Fecha límite (fin del día hábil N) contando el día de inicio como
     * día 1 si es hábil. Si el inicio cae domingo, el día 1 es el lunes.
     *
     * @param  CarbonInterface|string  $startDate  Fecha de inicio (día 1).
     * @param  int  $businessDays  Días hábiles de la ventana (mínimo 1).
     * @return Carbon Fin del día límite (23:59:59) en TZ operativo.
     */
    public static function deadline(CarbonInterface|string $startDate, int $businessDays): Carbon
    {
        $tz = config('manifests.dates.timezone', 'America/Tegucigalpa');

        $cursor = Carbon::parse(
            $startDate instanceof CarbonInterface ? $startDate->toDateString() : $startDate,
            $tz,
        )->startOfDay();

        $businessDays = max(1, $businessDays);
        $counted = 0;

        while (true) {
            if (! $cursor->isSunday()) {
                $counted++;

                if ($counted >= $businessDays) {
                    break;
                }
            }

            $cursor->addDay();
        }

        return $cursor->endOfDay();
    }

    /**
     * Días hábiles restantes hasta la fecha límite, contando HOY (si es
     * hábil) y el día límite inclusive. 0 si la fecha límite ya pasó.
     *
     * Ej.: hoy martes, límite miércoles → 2. Hoy = día límite → 1.
     */
    public static function remaining(CarbonInterface $deadline): int
    {
        $tz = config('manifests.dates.timezone', 'America/Tegucigalpa');

        $today = Carbon::now($tz)->startOfDay();
        $end = Carbon::parse($deadline->format('Y-m-d H:i:s'), $deadline->getTimezone())
            ->timezone($tz)
            ->startOfDay();

        if ($today->greaterThan($end)) {
            return 0;
        }

        $count = 0;
        $cursor = $today->copy();

        while ($cursor->lte($end)) {
            if (! $cursor->isSunday()) {
                $count++;
            }

            $cursor->addDay();
        }

        return $count;
    }
}
