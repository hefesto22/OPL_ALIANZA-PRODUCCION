<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Traslado de facturas entre bodegas.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  POR QUÉ ESTE SERVICIO EXISTE
 * ─────────────────────────────────────────────────────────────────────
 *  `invoices.warehouse_id` lo fija Jaremar en el campo `Almacen` del payload
 *  de importación. Cuando Jaremar se equivoca, la factura entra a la bodega
 *  incorrecta y NO hay forma de corregirlo: el importador inserta en bulk y
 *  nunca actualiza filas existentes, y la re-emisión queda bloqueada por la
 *  detección de duplicados. La única salida era un UPDATE a mano en producción.
 *
 *  Un UPDATE a mano rompe tres cosas a la vez:
 *
 *   1. AUDITORÍA. `Invoice` audita `warehouse_id` vía ActivityLog, pero solo
 *      si el cambio pasa por Eloquent. Un UPDATE crudo mueve dinero entre
 *      bodegas sin dejar quién, cuándo ni por qué.
 *
 *   2. TOTALES. `manifests.total_*` y `manifest_warehouse_totals` son columnas
 *      pre-calculadas. Sin `recalculateTotals()` el manifiesto queda mintiendo.
 *
 *   3. DEVOLUCIONES. La tabla `returns` lleva su PROPIO `warehouse_id`. Si la
 *      factura se mueve y su devolución no, la devolución sigue restando en la
 *      bodega vieja — y el endpoint `devoluciones/listar` le reporta a Jaremar
 *      un `almacen` que ya no corresponde a la factura.
 *
 *  Todo eso vive acá, en una sola transacción, para que el comando artisan y
 *  la acción del panel usen exactamente el mismo camino.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  POR QUÉ EL MANIFIESTO CERRADO SE BLOQUEA
 * ─────────────────────────────────────────────────────────────────────
 *  Un manifiesto cerrado es un período contable cuadrado y depositado. El
 *  traslado no cambia el total del manifiesto (la plata sigue siendo la
 *  misma), pero sí cambia qué bodega la reporta — y eso reescribe un reporte
 *  de ventas por bodega ya emitido. Si de verdad hay que hacerlo, el camino
 *  es reabrir el manifiesto (ManifestService::reopenManifest), trasladar, y
 *  volver a cerrarlo: queda el rastro completo de la reapertura.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  LO QUE ESTE SERVICIO NO ARREGLA
 * ─────────────────────────────────────────────────────────────────────
 *  Jaremar sigue teniendo la factura bajo la bodega vieja en SU sistema. Este
 *  servicio corrige a Hozana, no al proveedor. Cada traslado debería venir
 *  acompañado de un aviso al contacto de Jaremar para que corrijan el origen;
 *  si no, la próxima conciliación entre ambos sistemas va a discrepar.
 */
class InvoiceWarehouseTransferService
{
    /**
     * Largo mínimo del motivo. Un traslado de bodega mueve responsabilidad de
     * depósito entre encargados: "error" o "ok" no le sirven a nadie que lea
     * la bitácora dentro de seis meses.
     */
    public const MIN_REASON_LENGTH = 10;

    /**
     * Permiso personalizado que habilita el traslado (ver InvoicePolicy).
     *
     * Se valida acá, en el servicio, y no solo en la acción del panel: el
     * comando de consola es una segunda puerta al mismo poder, y una regla de
     * negocio que solo vive en la UI no es una regla de negocio.
     */
    public const PERMISSION = 'TransferWarehouse:Invoice';

    public function __construct(private readonly ReturnService $returnService) {}

    /**
     * Calcula el traslado SIN escribir nada.
     *
     * Devuelve el plan completo para que el llamador lo muestre (dry-run del
     * comando, confirmación del panel) y para que `transfer()` lo ejecute sin
     * recalcular la validación.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @param  User|null  $actor  Si se pasa, se valida factura por factura
     *                            contra InvoicePolicy::transferWarehouse.
     * @return array{
     *     movements: array<int, array{invoice_id:int, invoice_number:string, manifest_number:string,
     *                                 from_code:string, total:float, returns:int}>,
     *     already_there: array<int, string>,
     *     blockers: array<int, string>,
     *     total_amount: float,
     *     manifest_ids: array<int, int>
     * }
     */
    public function plan(Collection $invoices, Warehouse $target, ?User $actor = null): array
    {
        $movements = [];
        $alreadyThere = [];
        $blockers = [];
        $manifestIds = [];
        $totalAmount = 0.0;

        if (! $target->is_active) {
            $blockers[] = "La bodega destino {$target->code} está inactiva.";
        }

        // Las facturas se re-leen de la BD en vez de confiar en los modelos
        // que llegaron. Dos razones:
        //
        //   1. `warehouse_id` tiene que ser el de AHORA, no el que traía en
        //      memoria un componente Livewire abierto hace diez minutos. Si
        //      alguien ya movió la factura, el plan lo tiene que reflejar.
        //   2. Normaliza el tipo de colección. Una Collection base (la que sale
        //      de `collect([...])`) no tiene loadMissing — solo la de Eloquent —
        //      así que depender de eso hacía que el servicio funcionara o
        //      reventara según cómo lo llamara cada puerta.
        //
        // Son 3 queries fijas sin importar si vienen 3 facturas o 300.
        $ids = $invoices->pluck('id')->filter()->unique()->values();

        $invoices = Invoice::query()
            ->whereIn('id', $ids)
            ->with(['manifest:id,number,status', 'warehouse:id,code'])
            ->get();

        if ($invoices->count() !== $ids->count()) {
            $faltantes = $ids->count() - $invoices->count();
            $blockers[] = "{$faltantes} de las facturas seleccionadas ya no existen o fueron eliminadas.";
        }

        $returnCounts = InvoiceReturn::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->selectRaw('invoice_id, COUNT(*) AS total')
            ->groupBy('invoice_id')
            ->pluck('total', 'invoice_id');

        foreach ($invoices as $invoice) {
            if ((int) $invoice->warehouse_id === (int) $target->id) {
                $alreadyThere[] = $invoice->invoice_number;

                continue;
            }

            if ($actor !== null && ! $actor->can('transferWarehouse', $invoice)) {
                // El permiso general ya se validó en transfer(); si falla acá
                // es el alcance por bodega de la Policy.
                $blockers[] = "El usuario {$actor->email} no tiene alcance sobre la factura "
                    .$invoice->invoice_number.' (bodega '.($invoice->warehouse?->code ?? 'sin asignar').').';

                continue;
            }

            $manifest = $invoice->manifest;

            if ($manifest === null) {
                $blockers[] = "La factura {$invoice->invoice_number} no tiene manifiesto asociado.";

                continue;
            }

            if ($manifest->isClosed()) {
                $blockers[] = "La factura {$invoice->invoice_number} pertenece al manifiesto #{$manifest->number}, "
                    .'que ya está cerrado. Reabrilo primero si el traslado es indispensable.';

                continue;
            }

            $movements[] = [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'manifest_number' => (string) $manifest->number,
                'from_code' => $invoice->warehouse?->code ?? '—',
                'total' => (float) $invoice->total,
                'returns' => (int) ($returnCounts[$invoice->id] ?? 0),
            ];

            $manifestIds[(int) $manifest->id] = (int) $manifest->id;
            $totalAmount += (float) $invoice->total;
        }

        return [
            'movements' => $movements,
            'already_there' => $alreadyThere,
            'blockers' => $blockers,
            'total_amount' => round($totalAmount, 2),
            'manifest_ids' => array_values($manifestIds),
        ];
    }

    /**
     * Ejecuta el traslado. Todo o nada.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @param  string  $reason  Motivo operativo — queda en ActivityLog.
     * @param  User|null  $causer  Responsable. Si es NULL se toma el usuario
     *                             autenticado; sin ninguno de los dos, no hay
     *                             traslado.
     * @return array{invoices:int, returns:int, manifests:int, total_amount:float}
     *
     * @throws RuntimeException si no hay responsable autorizado, el motivo es
     *                          insuficiente, o hay bloqueos.
     */
    public function transfer(Collection $invoices, Warehouse $target, string $reason, ?User $causer = null): array
    {
        $actor = $causer ?? Auth::user();

        // Un traslado anónimo no existe: la bitácora quedaría sin responsable
        // justo en el movimiento que más falta hace poder atribuir.
        if (! $actor instanceof User) {
            throw new RuntimeException(
                'El traslado de bodega exige un usuario responsable identificado.'
            );
        }

        if (! $actor->can(self::PERMISSION)) {
            throw new RuntimeException(
                "El usuario {$actor->email} no tiene el permiso ".self::PERMISSION
                .' para trasladar facturas entre bodegas.'
            );
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new RuntimeException(
                'El traslado exige un motivo de al menos '.self::MIN_REASON_LENGTH.' caracteres: '
                .'es lo único que va a explicar el movimiento cuando alguien audite la bitácora.'
            );
        }

        $plan = $this->plan($invoices, $target, $actor);

        if ($plan['blockers'] !== []) {
            throw new RuntimeException(implode(' ', $plan['blockers']));
        }

        if ($plan['movements'] === []) {
            return ['invoices' => 0, 'returns' => 0, 'manifests' => 0, 'total_amount' => 0.0];
        }

        // Se juntan acá para invalidar el cache DESPUÉS del commit, igual que
        // hace ReturnService: si la transacción revienta, no se bota un cache
        // que seguía siendo válido.
        $touchedReturns = [];

        $result = DB::transaction(function () use ($plan, $target, $reason, $actor, &$touchedReturns): array {
            $movedInvoices = 0;
            $movedReturns = 0;

            foreach ($plan['manifest_ids'] as $manifestId) {
                // Lock pesimista sobre el manifiesto, mismo patrón que
                // DepositService: serializa este traslado contra un depósito
                // o una devolución que estén tocando los mismos totales.
                $manifest = Manifest::query()
                    ->whereKey($manifestId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Re-chequeo DENTRO del lock: entre el plan y la transacción
                // alguien pudo cerrar el manifiesto.
                if ($manifest->isClosed()) {
                    throw new RuntimeException(
                        "El manifiesto #{$manifest->number} fue cerrado mientras se preparaba el traslado."
                    );
                }

                $ids = collect($plan['movements'])
                    ->where('manifest_number', (string) $manifest->number)
                    ->pluck('invoice_id')
                    ->all();

                $locked = Invoice::query()
                    ->whereIn('id', $ids)
                    ->lockForUpdate()
                    ->get();

                $movedFrom = [];

                foreach ($locked as $invoice) {
                    if ((int) $invoice->warehouse_id === (int) $target->id) {
                        continue;
                    }

                    $fromId = $invoice->warehouse_id;
                    $movedFrom[$fromId ?? 0] = ($movedFrom[$fromId ?? 0] ?? 0) + 1;

                    $invoice->warehouse_id = $target->id;

                    // Una factura sin bodega vive en 'pending_warehouse'.
                    // Al asignarle una pasa a 'imported'. Los estados de
                    // devolución (returned / partial_return / rejected) NO se
                    // tocan: describen el ciclo de la factura, no su bodega.
                    if ($invoice->status === 'pending_warehouse') {
                        $invoice->status = 'imported';
                    }

                    $invoice->save();
                    $movedInvoices++;

                    // Las devoluciones viajan con su factura. Se recorren una
                    // por una a propósito (en vez de un mass update) para que
                    // ActivityLog registre cada cambio de bodega.
                    $returns = InvoiceReturn::query()
                        ->where('invoice_id', $invoice->id)
                        ->where(function ($q) use ($target) {
                            $q->whereNull('warehouse_id')
                                ->orWhere('warehouse_id', '!=', $target->id);
                        })
                        ->lockForUpdate()
                        ->get();

                    foreach ($returns as $return) {
                        $return->warehouse_id = $target->id;
                        $return->save();
                        $touchedReturns[] = $return;
                        $movedReturns++;
                    }
                }

                // Dentro de la TX: si algo revienta, los totales vuelven atrás
                // junto con las facturas.
                $manifest->recalculateTotals();

                activity()
                    ->performedOn($manifest)
                    ->causedBy($actor)
                    ->event('warehouse_transfer')
                    ->withProperties([
                        'motivo' => $reason,
                        'bodega_destino' => $target->code,
                        'bodegas_origen' => Warehouse::query()
                            ->whereIn('id', array_keys(array_filter($movedFrom, fn ($k) => $k > 0, ARRAY_FILTER_USE_KEY)))
                            ->pluck('code')
                            ->all(),
                        'facturas' => collect($plan['movements'])
                            ->where('manifest_number', (string) $manifest->number)
                            ->pluck('invoice_number')
                            ->all(),
                        'monto' => collect($plan['movements'])
                            ->where('manifest_number', (string) $manifest->number)
                            ->sum('total'),
                    ])
                    ->log("Traslado de bodega a {$target->code}");
            }

            return [
                'invoices' => $movedInvoices,
                'returns' => $movedReturns,
                'manifests' => count($plan['manifest_ids']),
                'total_amount' => $plan['total_amount'],
            ];
        });

        // El endpoint `devoluciones/listar` sirve `almacen` desde un cache
        // versionado por fecha. Si una devolución cambió de bodega y no se
        // bumpea el contador, Jaremar sigue leyendo el código viejo hasta que
        // el cache expire — justo el dato que se acaba de corregir.
        foreach ($touchedReturns as $return) {
            $this->returnService->invalidateDevolucionesCacheForReturn($return);
        }

        return $result;
    }
}
