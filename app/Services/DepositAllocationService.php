<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DepositAllocation;
use App\Models\Manifest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reparto FIFO de un depósito bancario entre manifiestos.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  EL CASO DE NEGOCIO
 * ─────────────────────────────────────────────────────────────────────
 *  El encargado de bodega no hace una transferencia por manifiesto: para
 *  ahorrarse comisiones y tiempo manda UNA boleta que cubre varios. Además
 *  los montos rara vez cuadran al centavo, así que el excedente de la boleta
 *  de hoy es exactamente lo que le falta a un manifiesto de la semana pasada.
 *
 *  Este servicio traduce "HNL 5,000 en el banco" a "HNL 4,999.68 al manifiesto
 *  de hoy + HNL 0.32 al manifiesto del 2 de julio".
 *
 * ─────────────────────────────────────────────────────────────────────
 *  ORDEN DEL REPARTO (FIFO) — Y POR QUÉ
 * ─────────────────────────────────────────────────────────────────────
 *  1. Primero el manifiesto de ORIGEN hasta su saldo pendiente. Es el que
 *     el operador tenía en pantalla; si el reparto empezara por otro lado,
 *     vería su propio manifiesto sin cubrir y pensaría que falló.
 *  2. Luego los candidatos del MÁS ANTIGUO al más nuevo. Los manifiestos
 *     viejos con centavos pendientes son los que llevan meses sin poder
 *     cerrarse — se atienden primero por definición de FIFO contable.
 *  3. Si aún sobra, el remanente vuelve al manifiesto de origen (quedando
 *     sobre-depositado). Esto mantiene la invariante SUM(reparto) == monto
 *     sin inventar un limbo de "dinero sin dueño"; ese manifiesto queda con
 *     difference negativa y se resuelve con un ajuste (ManifestAdjustment).
 *
 * ─────────────────────────────────────────────────────────────────────
 *  EL PLAN SE CALCULA DOS VECES, A PROPÓSITO
 * ─────────────────────────────────────────────────────────────────────
 *  - Una fuera de transacción, para PREVISUALIZAR en el modal de Filament.
 *  - Otra dentro de la transacción con los manifiestos ya bloqueados, que
 *    es la que se persiste.
 *  Entre ambas puede haber pasado otro depósito que consumió el pendiente
 *  de un candidato. Recalcular bajo lock es lo que impide sobre-acreditar;
 *  el paso 3 garantiza que el monto siempre cierra aunque el mundo cambie.
 */
class DepositAllocationService
{
    /**
     * Estados de manifiesto que pueden recibir dinero. 'closed' queda fuera:
     * un manifiesto cerrado está congelado y su total ya se reportó.
     *
     * @var array<int, string>
     */
    public const OPEN_STATUSES = ['imported', 'processing'];

    /**
     * Saldo pendiente REAL de un manifiesto, calculado desde las tablas de
     * detalle en vez de leer la columna cacheada `difference`.
     *
     * Se recalcula en vez de confiar en la columna porque este método corre
     * dentro de la transacción con el manifiesto bloqueado: en ese punto
     * `difference` puede reflejar el estado previo al lock, y aplicar sobre
     * un saldo viejo es exactamente la carrera que el lock viene a evitar.
     */
    public function pendingFor(Manifest $manifest): float
    {
        $applied = DepositAllocation::totalForManifest($manifest->id);
        $adjusted = (float) $manifest->adjustments()->sum('amount');

        return round(max(0, (float) $manifest->total_to_deposit - $applied - $adjusted), 2);
    }

    /**
     * Bodegas a las que pertenece un manifiesto.
     *
     * Se lee de manifest_warehouse_totals y NO de manifests.warehouse_id:
     * los manifiestos que entran por la API de Jaremar traen warehouse_id
     * NULL y su bodega vive en las facturas (un mismo manifiesto puede
     * abarcar OAC y OAS). Filtrar por warehouse_id dejaría fuera del reparto
     * justamente a los manifiestos reales de producción.
     *
     * @return array<int, int>
     */
    public function warehouseIdsOf(Manifest $manifest): array
    {
        $ids = $manifest->warehouseTotals()
            ->pluck('warehouse_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === [] && $manifest->warehouse_id !== null) {
            $ids = [(int) $manifest->warehouse_id];
        }

        return $ids;
    }

    /**
     * Manifiestos que pueden recibir el excedente de un depósito, del más
     * antiguo al más nuevo.
     *
     * Condiciones (todas obligatorias):
     *   - abierto (no cerrado)
     *   - mismo proveedor que el de origen
     *   - le falta dinero (difference > 0)
     *   - comparte al menos una bodega con el de origen
     *   - el usuario tiene acceso (mismo criterio que el listado de
     *     manifiestos: al menos una factura de sus bodegas)
     *
     * @return Collection<int, Manifest>
     */
    public function candidates(Manifest $origin, ?User $user = null): Collection
    {
        $warehouseIds = $this->warehouseIdsOf($origin);

        $query = Manifest::query()
            ->whereKeyNot($origin->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->where('supplier_id', $origin->supplier_id)
            ->where('difference', '>', 0);

        // Comparte bodega con el manifiesto de origen. Si el de origen no
        // tiene bodegas resueltas todavía (manifiesto recién importado sin
        // warehouseTotals), no se arriesga a repartir a ciegas: sin bodega
        // conocida no hay candidatos.
        if ($warehouseIds === []) {
            return collect();
        }

        $query->where(function (Builder $q) use ($warehouseIds) {
            $q->whereIn('warehouse_id', $warehouseIds)
                ->orWhereHas('warehouseTotals', fn (Builder $t) => $t->whereIn('warehouse_id', $warehouseIds));
        });

        // Scoping por usuario: mismo criterio que ManifestResource::
        // getEloquentQuery (pertenencia vía facturas), no WarehouseScope::
        // apply — que filtra por manifests.warehouse_id y dejaría fuera los
        // manifiestos multi-bodega de la API.
        if ($user && $user->isWarehouseUser()) {
            $query->whereHas('invoices', fn (Builder $q) => $q->whereIn('warehouse_id', $user->warehouseIds()));
        }

        return $query
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Calcula el reparto de un monto. Ver la cabecera de la clase para el
     * orden y su justificación.
     *
     * @param  Collection<int, Manifest>|null  $candidates  Candidatos ya
     *                                                      resueltos y (idealmente) bloqueados. Si es null los busca —
     *                                                      ese es el modo "previsualización" para la UI.
     * @return array<int, array{manifest_id: int, number: string, date: string|null, amount: float, is_origin: bool, is_overflow: bool}>
     */
    public function plan(Manifest $origin, float $amount, ?User $user = null, ?Collection $candidates = null): array
    {
        $amount = round($amount, 2);
        $remaining = $amount;
        $plan = [];

        // ── 1. El manifiesto de origen, hasta su pendiente ────────────
        $originPending = $this->pendingFor($origin);
        $toOrigin = round(min($remaining, $originPending), 2);

        if ($toOrigin > 0) {
            $plan[$origin->id] = $this->line($origin, $toOrigin, true, false);
            $remaining = round($remaining - $toOrigin, 2);
        }

        // ── 2. Candidatos, del más antiguo al más nuevo ───────────────
        if ($remaining > 0) {
            $candidates ??= $this->candidates($origin, $user);

            foreach ($candidates as $candidate) {
                if ($remaining <= 0) {
                    break;
                }

                $pending = $this->pendingFor($candidate);

                if ($pending <= 0) {
                    continue;
                }

                $take = round(min($remaining, $pending), 2);
                $plan[$candidate->id] = $this->line($candidate, $take, false, false);
                $remaining = round($remaining - $take, 2);
            }
        }

        // ── 3. Remanente → manifiesto de origen (sobredepósito) ───────
        // Único escenario en que un manifiesto queda con difference negativa.
        // Exige justificación (la valida DepositService) y se resuelve luego
        // con un ajuste o revirtiendo el depósito.
        if ($remaining > 0) {
            if (isset($plan[$origin->id])) {
                $plan[$origin->id]['amount'] = round($plan[$origin->id]['amount'] + $remaining, 2);
                $plan[$origin->id]['is_overflow'] = true;
            } else {
                $plan[$origin->id] = $this->line($origin, $remaining, true, true);
            }
        }

        return array_values($plan);
    }

    /**
     * @return array{manifest_id: int, number: string, date: string|null, amount: float, is_origin: bool, is_overflow: bool}
     */
    private function line(Manifest $manifest, float $amount, bool $isOrigin, bool $isOverflow): array
    {
        return [
            'manifest_id' => (int) $manifest->id,
            'number' => (string) $manifest->number,
            'date' => $manifest->date?->toDateString(),
            'amount' => round($amount, 2),
            'is_origin' => $isOrigin,
            'is_overflow' => $isOverflow,
        ];
    }
}
