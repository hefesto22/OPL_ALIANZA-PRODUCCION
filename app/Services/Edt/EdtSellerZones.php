<?php

namespace App\Services\Edt;

use App\Models\Edt\EdtClient;
use App\Models\Edt\EdtSeller;
use App\Models\User;
use App\Support\Edt\EdtModule;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Qué clientes le tocan a un vendedor: los de SU bodega en los municipios
 * de las rutas que tiene asignadas y vigentes en la fecha (hoy por
 * defecto). Con $onlyVisitDay, solo las rutas que se visitan ese día de la
 * semana (o que no tienen días configurados).
 *
 * Se arma como subconsulta (WHERE municipality_id IN (...)): una sola query
 * sin importar cuántas rutas o municipios tenga el vendedor.
 *
 * Al vendedor no le aparecen los clientes inactivos (applyForUser /
 * userCanSee); en las consultas de zona en sí (applyToClients) sí cuentan,
 * para que admin y encargado vean la ruta completa.
 *
 * Un usuario con el rol vendedor_edt pero SIN ficha de vendedor no ve
 * ningún cliente: sin ficha no hay zonas, y sin este corte vería todos los
 * de su bodega (p.ej. si alguien le da el rol a mano desde Shield).
 */
final class EdtSellerZones
{
    /**
     * municipality_id de las rutas vigentes del vendedor en la fecha.
     */
    public static function municipalityIds(EdtSeller $seller, ?CarbonInterface $on = null, bool $onlyVisitDay = false): QueryBuilder
    {
        $date = $on ?? today();
        $day = $date->toDateString();

        return DB::table('edt_route_municipality')
            ->select('edt_route_municipality.municipality_id')
            ->join('edt_routes', 'edt_routes.id', '=', 'edt_route_municipality.route_id')
            ->join('edt_route_assignments', 'edt_route_assignments.route_id', '=', 'edt_routes.id')
            ->where('edt_route_assignments.seller_id', $seller->id)
            ->where('edt_routes.is_active', true)
            ->where('edt_route_assignments.starts_on', '<=', $day)
            ->where(fn (QueryBuilder $query) => $query
                ->whereNull('edt_route_assignments.ends_on')
                ->orWhere('edt_route_assignments.ends_on', '>=', $day))
            ->when($onlyVisitDay, fn (QueryBuilder $query) => $query->where(fn (QueryBuilder $query) => $query
                ->whereRaw("edt_routes.visit_days = '[]'::jsonb")
                ->orWhereRaw('edt_routes.visit_days @> ?::jsonb', [json_encode([$date->dayOfWeekIso])])));
    }

    /**
     * Limita una consulta de clientes a los del vendedor.
     */
    public static function applyToClients(Builder $clients, EdtSeller $seller, ?CarbonInterface $on = null, bool $onlyVisitDay = false): Builder
    {
        return $clients
            ->where('edt_clients.warehouse_id', $seller->warehouse_id)
            ->whereIn('edt_clients.municipality_id', self::municipalityIds($seller, $on, $onlyVisitDay));
    }

    /**
     * Si el usuario es vendedor, limita la consulta a sus clientes; si no,
     * la deja igual (el filtro por bodega lo pone WarehouseScope).
     */
    public static function applyForUser(Builder $clients, ?User $user): Builder
    {
        if ($user === null) {
            return $clients;
        }

        if ($seller = $user->edtSeller) {
            return self::applyToClients($clients, $seller)->where('edt_clients.is_active', true);
        }

        return self::isSellerWithoutProfile($user) ? $clients->whereRaw('false') : $clients;
    }

    /**
     * ¿El usuario puede ver este cliente según sus zonas? Para los que no
     * son vendedores siempre es true (la bodega la revisa la Policy aparte).
     */
    public static function userCanSee(User $user, EdtClient $client): bool
    {
        if ($seller = $user->edtSeller) {
            return $client->is_active
                && (int) $client->warehouse_id === (int) $seller->warehouse_id
                && self::municipalityIds($seller)
                    ->where('edt_route_municipality.municipality_id', $client->municipality_id)
                    ->exists();
        }

        return ! self::isSellerWithoutProfile($user);
    }

    private static function isSellerWithoutProfile(User $user): bool
    {
        return $user->hasRole(EdtModule::SELLER_ROLE);
    }
}
