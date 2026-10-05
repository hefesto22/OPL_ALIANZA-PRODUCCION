<?php

namespace App\Services\Edt;

use App\Models\Edt\EdtRoute;
use App\Models\Edt\EdtRouteAssignment;
use App\Models\Edt\EdtSeller;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Quién tiene cada ruta y desde cuándo.
 *
 * Las asignaciones de una ruta forman una línea de tiempo sin huecos
 * obligatorios pero SIN cruces: nunca dos vendedores en la misma ruta el
 * mismo día. Todo cambio bloquea la fila de la ruta (lockForUpdate), así
 * que dos encargados reasignando la misma ruta a la vez no la dejan con
 * dos vendedores. El índice único parcial de la tabla (una sola asignación
 * abierta por ruta) es la última defensa.
 */
class EdtRouteAssignmentService
{
    /**
     * Asigna la ruta al vendedor desde $startsOn (puede ser una fecha
     * futura). Si la ruta tiene un vendedor con la asignación abierta, esa
     * termina el día anterior.
     *
     * @throws EdtRuleException
     */
    public function assign(EdtRoute $route, EdtSeller $seller, CarbonInterface $startsOn): EdtRouteAssignment
    {
        $startsOn = $startsOn->copy()->startOfDay();

        return DB::transaction(function () use ($route, $seller, $startsOn): EdtRouteAssignment {
            $route = $this->lock($route);

            if ((int) $seller->warehouse_id !== (int) $route->warehouse_id) {
                throw new EdtRuleException('El vendedor es de otra bodega: una ruta solo se asigna a vendedores de su bodega.');
            }
            if (! $seller->is_active) {
                throw new EdtRuleException('El vendedor está inactivo.');
            }
            if (! $route->is_active) {
                throw new EdtRuleException('La ruta está inactiva.');
            }

            $latest = $route->assignments()->orderByDesc('starts_on')->first();

            if ($latest !== null) {
                if ($latest->starts_on->gte($startsOn)) {
                    throw new EdtRuleException(
                        'La ruta ya tiene una asignación que empieza el '.$latest->starts_on->format('d/m/Y').': la nueva tiene que empezar después.'
                    );
                }

                if ($latest->ends_on === null) {
                    if ((int) $latest->seller_id === (int) $seller->id) {
                        throw new EdtRuleException('Ese vendedor ya tiene esta ruta.');
                    }

                    $latest->update(['ends_on' => $startsOn->copy()->subDay()]);
                } elseif ($latest->ends_on->gte($startsOn)) {
                    throw new EdtRuleException(
                        'La asignación anterior termina el '.$latest->ends_on->format('d/m/Y').': la nueva tiene que empezar después.'
                    );
                }
            }

            return EdtRouteAssignment::query()->create([
                'warehouse_id' => $route->warehouse_id,
                'route_id' => $route->id,
                'seller_id' => $seller->id,
                'starts_on' => $startsOn,
                'ends_on' => null,
            ]);
        });
    }

    /**
     * Quita el vendedor de la ruta: su asignación abierta termina en $endsOn
     * (inclusive). La ruta queda sin vendedor desde el día siguiente.
     *
     * @throws EdtRuleException
     */
    public function release(EdtRoute $route, CarbonInterface $endsOn): EdtRouteAssignment
    {
        $endsOn = $endsOn->copy()->startOfDay();

        return DB::transaction(function () use ($route, $endsOn): EdtRouteAssignment {
            $route = $this->lock($route);

            $open = $route->assignments()->whereNull('ends_on')->first();

            if ($open === null) {
                throw new EdtRuleException('La ruta no tiene vendedor asignado.');
            }
            if ($endsOn->lt($open->starts_on)) {
                throw new EdtRuleException(
                    'La asignación empieza el '.$open->starts_on->format('d/m/Y').': no puede terminar antes. Si fue un error, cancela la programación.'
                );
            }

            $open->update(['ends_on' => $endsOn]);

            return $open;
        });
    }

    /**
     * Cancela una asignación programada que todavía no empieza (un error al
     * asignar). Si al programarla se cerró la anterior, esa vuelve a quedar
     * abierta, como estaba antes.
     *
     * @throws EdtRuleException
     */
    public function cancelScheduled(EdtRoute $route, ?CarbonInterface $today = null): void
    {
        $today = ($today ?? today())->copy()->startOfDay();

        DB::transaction(function () use ($route, $today): void {
            $route = $this->lock($route);

            $scheduled = $route->assignments()->whereNull('ends_on')->first();

            if ($scheduled === null || $scheduled->starts_on->lte($today)) {
                throw new EdtRuleException('La ruta no tiene una asignación programada a futuro para cancelar.');
            }

            $previous = $route->assignments()
                ->whereKeyNot($scheduled->id)
                ->whereDate('ends_on', $scheduled->starts_on->copy()->subDay())
                ->first();

            $scheduled->delete();
            $previous?->update(['ends_on' => null]);
        });
    }

    private function lock(EdtRoute $route): EdtRoute
    {
        return EdtRoute::query()->whereKey($route->id)->lockForUpdate()->firstOrFail();
    }
}
