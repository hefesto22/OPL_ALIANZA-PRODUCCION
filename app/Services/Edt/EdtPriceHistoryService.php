<?php

namespace App\Services\Edt;

use App\Casts\Uppercase;
use App\Enums\Edt\PriceChangeReason;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtProductPriceHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Escribe el historial de precios del EDT: datos de entrada + foto de los
 * precios resultantes (ver migración create_edt_product_price_history).
 *
 * Lo llaman los observers (producto, proveedor, escalas) dentro de la
 * transacción del cambio, así que el historial y el cambio se confirman o se
 * revierten juntos. No llamar desde fuera de esa transacción.
 */
class EdtPriceHistoryService
{
    /** Filas por INSERT en los recálculos masivos. */
    private const CHUNK = 500;

    /**
     * Llave del advisory lock de Postgres que pone en fila TODOS los cambios
     * que mueven precios del EDT ("EDTP" en hexadecimal).
     */
    private const LOCK_KEY = 0x45445450;

    public function __construct(private readonly EdtPricingService $pricing) {}

    /**
     * Una fila para un producto.
     */
    public function record(EdtProduct $product, PriceChangeReason $reason, ?string $note = null): EdtProductPriceHistory
    {
        $this->waitForOtherPriceChanges();

        // El descuento se lee del proveedor confirmado, no de la relación que
        // el modelo tenga cargada (puede ser vieja o de otro proveedor).
        $product->unsetRelation('supplier');

        return EdtProductPriceHistory::create($this->rowFor($product, $reason, $note));
    }

    /**
     * Una fila por cada producto del query, insertadas en lotes. Para los
     * cambios que mueven precios de muchos productos a la vez (descuento del
     * proveedor, escalas). Con el catálogo del EDT (cientos a pocos miles de
     * productos) es un INSERT por cada 500 — menos de un segundo.
     *
     * @param  Builder<EdtProduct>|Relation<EdtProduct, *, *>  $products
     * @return int filas escritas
     */
    public function recordForProducts(Builder|Relation $products, PriceChangeReason $reason, ?string $note = null): int
    {
        $this->waitForOtherPriceChanges();

        $written = 0;

        $products
            ->with('supplier:id,operation_discount_pct')
            ->chunkById(self::CHUNK, function (Collection $chunk) use ($reason, $note, &$written): void {
                $rows = $chunk
                    ->map(function (EdtProduct $product) use ($reason, $note): array {
                        $row = $this->rowFor($product, $reason, $note);
                        // insert() no pasa por los casts del modelo.
                        $row['wholesale_prices'] = json_encode($row['wholesale_prices'], JSON_THROW_ON_ERROR);
                        $row['created_at'] = $row['updated_at'] = now();

                        return $row;
                    })
                    ->all();

                EdtProductPriceHistory::query()->insert($rows);
                $written += count($rows);
            });

        return $written;
    }

    /**
     * Pone en fila a quien esté cambiando precios del EDT al mismo tiempo.
     *
     * Sin esto, dos cambios simultáneos (p. ej. dos escalas, o «Cambiar
     * precio» mientras cambia el descuento del proveedor) calcularían cada uno
     * con el dato VIEJO del otro, y la última foto del historial no cuadraría
     * con el precio vigente. El lock se toma dentro de la transacción del
     * cambio (SavesAtomically), DESPUÉS del UPDATE propio, y se suelta solo al
     * confirmar o revertir: quien escribe último ya ve lo confirmado por los
     * demás. Por eso las escalas se vuelven a leer después de tomarlo.
     */
    private function waitForOtherPriceChanges(): void
    {
        DB::statement('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

        $this->pricing->refreshTiers();
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(EdtProduct $product, PriceChangeReason $reason, ?string $note): array
    {
        $quote = $this->pricing->quoteForProduct($product);

        return [
            'product_id' => $product->id,
            'reason' => $reason->value,
            // Regla del EDT: todo el texto en mayúsculas.
            'note' => Uppercase::normalize($note),
            'list_price' => (string) $product->list_price,
            'units_per_box' => (int) $product->units_per_box,
            'isv_pct' => (string) $product->isv_pct,
            'operation_discount_pct' => (string) $product->supplier->operation_discount_pct,
            'unit_cost' => $quote->unitCost,
            'retail_unit_price' => $quote->retailUnitPrice,
            'retail_box_price' => $quote->retailBoxPrice,
            'wholesale_prices' => array_map(
                fn (array $tier): array => [
                    'tier_id' => $tier['tier_id'],
                    'name' => $tier['name'],
                    'min_boxes' => $tier['min_boxes'],
                    'discount_pct' => $tier['discount_pct'],
                    'box_price' => $tier['box_price'],
                ],
                $quote->wholesale,
            ),
            'created_by' => Auth::id(),
        ];
    }
}
