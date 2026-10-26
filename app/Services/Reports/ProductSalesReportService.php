<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\User;
use App\Models\Warehouse;
use App\Support\BoxEquivalence;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reporte de ventas por producto: de lo que entró en un período, cuánto vino
 * bonificado, cuánto se devolvió y cuánto se movió en neto.
 *
 * Cómo se lee una fila (decidido con Mauricio, 2026-10-25):
 *
 *   PASTA 200G   Entró 1,000 (de eso 50 bonificación) · Devuelto 50 · Neto 950
 *
 *   - La bonificación es un DESGLOSE de lo que entró: no resta.
 *   - Solo la devolución resta: neto = entró − devuelto.
 *   - Las devoluciones que cuentan son las de las facturas del período,
 *     aunque se hayan registrado después ("de lo que llegó en marzo, cuánto
 *     se devolvió"). Por eso un período reciente todavía puede cambiar
 *     mientras sus manifiestos tengan abierta la ventana de devoluciones.
 *
 * De dónde sale cada número (nada se recalcula a mano):
 *   - Entró: unidades reales de cada línea, con la misma regla de la Sublista
 *     para líneas mixtas (cajas embebidas que no venían en las fracciones).
 *   - Bonificación: líneas con valor L 0.00 que traen mercadería — la misma
 *     definición que usan la Sublista y el checklist de facturas.
 *   - Devuelto: invoice_lines.returned_quantity, que ReturnService mantiene
 *     con las devoluciones aprobadas y pendientes (las canceladas no cuentan).
 *   - Monto devuelto: SUM(return_lines.line_total) con los mismos estados, así
 *     cuadra al centavo con el módulo de Devoluciones.
 *   - Totales del encabezado: SUM(invoices.total) y SUM(invoices.total_returns),
 *     el valor fiscal que se deposita (igual que la Sublista). La suma de las
 *     filas puede diferir centavos por el redondeo de Jaremar entre el total
 *     de la factura y sus líneas.
 *
 * Fuera del reporte: facturas rechazadas, facturas borradas y facturas sin
 * bodega asignada.
 *
 * Traslados: una factura trasladada cuenta en la bodega donde quedó; la
 * sección de ProductSalesTransfersSection explica de dónde vino o a dónde se
 * fue, y quién registró sus devoluciones.
 *
 * Costo: tres agregaciones sobre invoices ⨝ invoice_lines filtradas por
 * (warehouse_id, invoice_date) — índice idx_invoices_warehouse_date, siempre
 * con la lista explícita de bodegas — y (invoice_id, product_id). O(líneas
 * del período). El rango se limita a 3 meses en ProductSalesFilters.
 */
final class ProductSalesReportService
{
    public const PERMISSION = 'ReportProductSales:Invoice';

    public const CACHE_TTL_SECONDS = 120;

    public const SORT_NET_AMOUNT = 'net_amount';

    public const SORT_RETURNED = 'returned';

    public const SORT_CODE = 'code';

    public const SORT_DESCRIPTION = 'description';

    /**
     * Estados de devolución que cuentan como devuelto. Son los mismos con los
     * que ReturnService calcula returned_quantity y total_returns.
     */
    private const COUNTED_RETURN_STATUSES = ['approved', 'pending'];

    /**
     * Unidades reales de una línea. Si quantity_fractions es menor que las
     * cajas × factor, la línea vino cruda (cajas aparte) y se suman; si no, las
     * fracciones ya incluyen las cajas. Misma regla que la Sublista.
     */
    public const LINE_UNITS_SQL = '(CASE
            WHEN il.quantity_fractions < il.quantity_box * il.conversion_factor
            THEN il.quantity_box * il.conversion_factor + il.quantity_fractions
            ELSE il.quantity_fractions
        END)';

    public function __construct(
        private readonly ProductSalesTransfersSection $transfers = new ProductSalesTransfersSection,
    ) {}

    /**
     * El mismo reporte, cacheado por filtros durante 2 minutos. La pantalla,
     * la impresión y el Excel piden la misma clave, así que muestran los mismos
     * números y la consulta corre una sola vez aunque el usuario imprima y
     * exporte seguido.
     *
     * @return array<string, mixed> Ver generate().
     */
    public function generateCached(ProductSalesFilters $filters): array
    {
        return Cache::remember(
            $filters->cacheKey(),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->generate($filters),
        );
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     warehouses: list<array{id: int, code: string, name: string}>,
     *     open_return_windows: int,
     *     transfers: array<string, mixed>,
     *     filters: array<string, mixed>,
     *     date_field_label: string,
     *     generated_at: string,
     * }
     */
    public function generate(ProductSalesFilters $filters): array
    {
        $returnedAmounts = $this->returnedAmountsByProduct($filters);

        $rows = [];
        foreach ($this->linesByProduct($filters) as $line) {
            $rows[] = $this->buildRow($line, (float) ($returnedAmounts[$line->product_id] ?? 0));
        }

        return [
            'rows' => $rows,
            'totals' => $this->totals($rows, $this->invoiceTotals($filters)),
            'warehouses' => Warehouse::query()
                ->whereIn('id', $filters->warehouseIds)
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Warehouse $warehouse): array => [
                    'id' => (int) $warehouse->id,
                    'code' => (string) $warehouse->code,
                    'name' => (string) $warehouse->name,
                ])
                ->all(),
            'open_return_windows' => $this->openReturnWindows($filters),
            'transfers' => $this->transfers->build($filters),
            'filters' => $filters->toArray(),
            'date_field_label' => $filters->dateFieldLabel(),
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }

    /**
     * Bodegas que el usuario puede consultar, cruzadas con las que pidió.
     *
     * Un usuario de bodega solo consulta las suyas, pida lo que pida (el valor
     * viene del navegador y no se confía en él). Un usuario global consulta
     * cualquiera. Si no pidió ninguna, o ninguna de las pedidas le corresponde,
     * se usan todas las que puede ver.
     *
     * @param  array<int, mixed>  $requested
     * @return list<int>
     */
    public function resolveWarehouseIds(User $user, array $requested): array
    {
        $allowed = $this->allowedWarehouseIds($user);
        $requested = array_map('intval', $requested);

        $ids = array_values(array_intersect($allowed, $requested));

        return $ids === [] ? $allowed : $ids;
    }

    /**
     * Opciones del selector de bodega, solo con las que el usuario puede ver.
     *
     * @return array<int, string>
     */
    public function warehouseOptions(User $user): array
    {
        return Warehouse::query()
            ->whereIn('id', $this->allowedWarehouseIds($user))
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (Warehouse $warehouse): array => [
                (int) $warehouse->id => "{$warehouse->code} — {$warehouse->name}",
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function sortOptions(): array
    {
        return [
            self::SORT_NET_AMOUNT => 'Mayor venta neta (L)',
            self::SORT_RETURNED => 'Más devueltos (L)',
            self::SORT_CODE => 'Código',
            self::SORT_DESCRIPTION => 'Nombre del producto',
        ];
    }

    /**
     * Ordena las filas en PHP: son una por producto (cientos, no millones) y
     * así el orden no forma parte de la clave de caché.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function sortRows(array $rows, string $sort): array
    {
        usort($rows, match ($sort) {
            self::SORT_RETURNED => fn (array $a, array $b): int => [$b['returned_amount'], $b['returned_units'], $a['product_id']]
                <=> [$a['returned_amount'], $a['returned_units'], $b['product_id']],
            self::SORT_CODE => fn (array $a, array $b): int => $a['product_id'] <=> $b['product_id'],
            self::SORT_DESCRIPTION => fn (array $a, array $b): int => [$a['description'], $a['product_id']]
                <=> [$b['description'], $b['product_id']],
            default => fn (array $a, array $b): int => [$b['net_amount'], $a['product_id']]
                <=> [$a['net_amount'], $b['product_id']],
        });

        return $rows;
    }

    /**
     * Descompone unidades en cajas + sueltas y arma la etiqueta que se ve en
     * pantalla e impresión ("12 cj + 5 u"). Sin factor de caja, todo es suelto.
     *
     * @return array{cajas: int, sueltas: int, label: string}
     */
    public static function boxes(int $units, int $factor): array
    {
        $split = BoxEquivalence::split($units, $factor);

        return [
            'cajas' => $split['cajas'],
            'sueltas' => $split['sueltas'],
            'label' => self::boxesLabel($split['cajas'], $split['sueltas']),
        ];
    }

    /**
     * @return list<int>
     */
    private function allowedWarehouseIds(User $user): array
    {
        $ids = $user->isWarehouseUser()
            ? $user->warehouseIds()
            : Warehouse::query()->pluck('id')->all();

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /**
     * Una fila por producto con lo que entró, lo bonificado, lo devuelto y lo
     * facturado. Un producto que vino en CJ y en UN sale en UNA sola fila.
     *
     * @return Collection<int, object>
     */
    private function linesByProduct(ProductSalesFilters $filters): Collection
    {
        $query = DB::table('invoice_lines as il')
            ->join('invoices as i', 'i.id', '=', 'il.invoice_id')
            ->select([
                'il.product_id',
                DB::raw('MIN(il.product_description) as product_description'),
                DB::raw("STRING_AGG(DISTINCT il.unit_sale, '/' ORDER BY il.unit_sale) as unit_sale"),
                DB::raw('MAX(il.conversion_factor) as conversion_factor'),
                DB::raw('SUM('.self::LINE_UNITS_SQL.') as entered_units'),
                DB::raw('SUM(CASE WHEN il.total = 0 THEN '.self::LINE_UNITS_SQL.' ELSE 0 END) as bonus_units'),
                DB::raw('SUM(il.returned_quantity) as returned_units'),
                DB::raw('SUM(il.total) as billed_amount'),
            ])
            ->groupBy('il.product_id')
            ->orderBy('il.product_id');

        return $this->scopeInvoices($query, $filters)->get();
    }

    /**
     * Monto devuelto por producto, desde las líneas de devolución.
     *
     * @return array<string, string|float>
     */
    private function returnedAmountsByProduct(ProductSalesFilters $filters): array
    {
        $query = DB::table('return_lines as rl')
            ->join('returns as r', 'r.id', '=', 'rl.return_id')
            ->join('invoice_lines as il', 'il.id', '=', 'rl.invoice_line_id')
            ->join('invoices as i', 'i.id', '=', 'il.invoice_id')
            ->whereNull('r.deleted_at')
            ->whereIn('r.status', self::COUNTED_RETURN_STATUSES)
            ->groupBy('il.product_id')
            ->select(['il.product_id', DB::raw('SUM(rl.line_total) as returned_amount')]);

        return $this->scopeInvoices($query, $filters)
            ->pluck('returned_amount', 'product_id')
            ->all();
    }

    /**
     * Conteo y totales fiscales de las facturas del período.
     */
    private function invoiceTotals(ProductSalesFilters $filters): object
    {
        $query = DB::table('invoices as i')->selectRaw(
            'COUNT(*) as invoices_count, '.
            'COALESCE(SUM(i.total), 0) as billed_total, '.
            'COALESCE(SUM(i.total_returns), 0) as returned_total'
        );

        return $this->scopeInvoices($query, $filters)->first();
    }

    /**
     * Manifiestos del período que todavía aceptan devoluciones. Un manifiesto
     * sin fecha límite (anterior a la regla de la ventana) no se cuenta: si no,
     * el aviso saldría siempre para los meses viejos.
     */
    private function openReturnWindows(ProductSalesFilters $filters): int
    {
        $manifestIds = $this->scopeInvoices(
            DB::table('invoices as i')->select('i.manifest_id')->distinct(),
            $filters,
        );

        return DB::table('manifests')
            ->whereIn('id', $manifestIds)
            ->whereNull('deleted_at')
            ->where('returns_deadline_at', '>', now())
            ->count();
    }

    /**
     * Facturas que entran al reporte. Espera la tabla `invoices` con alias `i`.
     */
    private function scopeInvoices(Builder $query, ProductSalesFilters $filters): Builder
    {
        return $filters->scopeInvoices($query);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(object $line, float $returnedAmount): array
    {
        $factor = (int) ($line->conversion_factor ?? 0);
        $entered = (int) round((float) $line->entered_units);
        $bonus = (int) round((float) $line->bonus_units);
        $returned = (int) round((float) $line->returned_units);
        // ReturnService no deja devolver más de lo facturado; el max() es
        // solo para que un dato corrupto no pinte cantidades negativas.
        $net = max(0, $entered - $returned);
        $billed = round((float) $line->billed_amount, 2);
        $returnedAmount = round($returnedAmount, 2);

        return [
            'product_id' => (string) $line->product_id,
            'description' => (string) $line->product_description,
            'unit_sale' => $line->unit_sale !== null ? (string) $line->unit_sale : null,
            'factor' => $factor,
            'entered_units' => $entered,
            'bonus_units' => $bonus,
            'returned_units' => $returned,
            'net_units' => $net,
            'entered' => self::boxes($entered, $factor),
            'bonus' => self::boxes($bonus, $factor),
            'returned' => self::boxes($returned, $factor),
            'net' => self::boxes($net, $factor),
            'return_pct' => $entered > 0 ? round($returned * 100 / $entered, 1) : 0.0,
            'billed_amount' => $billed,
            'returned_amount' => $returnedAmount,
            'net_amount' => round($billed - $returnedAmount, 2),
        ];
    }

    /**
     * Totales del reporte. Las cantidades suman las cajas y sueltas de cada
     * fila (como el pie de la Sublista); los montos son los fiscales.
     *
     * Las cajas del total no restan exacto (Entró − Devuelto ≠ Se movió) cuando
     * en un producto se devolvieron más sueltas de las que entraron sueltas: la
     * devolución abre una caja y esas unidades pasan a sueltas. Por eso cada
     * cantidad trae también sus unidades (ahí la resta sí es exacta) y
     * `products_with_opened_box` cuenta en cuántos productos pasó, para
     * explicarlo en el cuadro.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows, object $invoiceTotals): array
    {
        $billed = round((float) $invoiceTotals->billed_total, 2);
        $returned = round((float) $invoiceTotals->returned_total, 2);
        $returnPct = $billed > 0 ? round($returned * 100 / $billed, 1) : 0.0;

        $totals = [
            'products_count' => count($rows),
            'invoices_count' => (int) $invoiceTotals->invoices_count,
            'billed_total' => $billed,
            'returned_total' => $returned,
            'net_total' => round($billed - $returned, 2),
            'return_pct' => $returnPct,
            // 100 − lo devuelto, para que los dos porcentajes sumen 100 a la vista.
            'net_pct' => $billed > 0 ? round(100 - $returnPct, 1) : 0.0,
        ];

        foreach (['entered', 'bonus', 'returned', 'net'] as $key) {
            $cajas = array_sum(array_column(array_column($rows, $key), 'cajas'));
            $sueltas = array_sum(array_column(array_column($rows, $key), 'sueltas'));

            $totals[$key] = [
                'cajas' => $cajas,
                'sueltas' => $sueltas,
                'units' => array_sum(array_column($rows, $key.'_units')),
                'label' => self::boxesLabel($cajas, $sueltas),
            ];
        }

        $enteredUnits = $totals['entered']['units'];
        $returnedUnitsPct = $enteredUnits > 0 ? round($totals['returned']['units'] * 100 / $enteredUnits, 1) : 0.0;

        $totals['returned_units_pct'] = $returnedUnitsPct;
        $totals['net_units_pct'] = $enteredUnits > 0 ? round(100 - $returnedUnitsPct, 1) : 0.0;
        $totals['bonus_units_pct'] = $enteredUnits > 0 ? round($totals['bonus']['units'] * 100 / $enteredUnits, 1) : 0.0;
        $totals['products_with_opened_box'] = count(array_filter(
            $rows,
            fn (array $row): bool => $row['entered']['cajas'] - $row['returned']['cajas'] - $row['net']['cajas'] > 0,
        ));

        return $totals;
    }

    public static function boxesLabel(int $cajas, int $sueltas): string
    {
        $parts = [];
        if ($cajas > 0) {
            $parts[] = number_format($cajas).' cj';
        }
        if ($sueltas > 0) {
            $parts[] = number_format($sueltas).' u';
        }

        return $parts === [] ? '—' : implode(' + ', $parts);
    }
}
