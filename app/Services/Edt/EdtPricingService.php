<?php

namespace App\Services\Edt;

use App\Models\Edt\EdtPriceTier;
use App\Models\Edt\EdtProduct;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;

/**
 * Cálculo de precios del EDT — la única fuente de verdad.
 *
 * Reproduce el Excel "Calculo Precios - EDT Honduras" del cliente (verificado
 * con sus 188 filas: tests/Fixtures/Edt/precios_excel_edt.csv). Todo con
 * decimales exactos (brick/math), nunca float.
 *
 * Entradas: precio de lista SIN ISV (= precio de venta al detalle sin ISV),
 * unidades por caja, ISV del producto, descuento de operación del proveedor
 * y las escalas mayoristas activas.
 *
 *   costo por unidad   = lista × (1 − desc. operación) × (1 + ISV)       → 4 decimales
 *   detalle por unidad = lista × (1 + ISV)                               → 2 decimales (mitad hacia arriba)
 *   detalle por caja   = detalle por unidad × unidades por caja
 *   mayorista por caja = lista × unidades × (1 − desc. escala) × (1 + ISV) → hacia ARRIBA al lempira
 *
 * El "hacia arriba al lempira" del mayorista es el ROUNDUP(…; 0) del Excel.
 *
 * #[Scoped]: una instancia por request/job, así las escalas activas se leen
 * de la BD una sola vez aunque una tabla calcule precios de 25 productos.
 */
#[Scoped]
class EdtPricingService
{
    /** @var Collection<int, EdtPriceTier>|null */
    private ?Collection $activeTiers = null;

    /**
     * @param  iterable<EdtPriceTier>  $tiers
     */
    public function quote(
        string $listPrice,
        int $unitsPerBox,
        string $isvPct,
        string $operationDiscountPct,
        iterable $tiers,
    ): EdtPriceQuote {
        $list = BigDecimal::of($listPrice);
        $isvFactor = $this->factorFromPct($isvPct, add: true);
        $costFactor = $this->factorFromPct($operationDiscountPct, add: false);

        $unitCost = $list->multipliedBy($costFactor)->multipliedBy($isvFactor);
        $retailUnit = $list->multipliedBy($isvFactor)->toScale(2, RoundingMode::HalfUp);
        $retailBox = $retailUnit->multipliedBy($unitsPerBox);

        $wholesale = [];
        foreach ($this->sortTiers($tiers) as $tier) {
            $boxPrice = $list
                ->multipliedBy($unitsPerBox)
                ->multipliedBy($this->factorFromPct((string) $tier->discount_pct, add: false))
                ->multipliedBy($isvFactor)
                ->toScale(0, RoundingMode::Ceiling)
                ->toScale(2);

            $wholesale[] = [
                'tier_id' => $tier->id,
                'name' => (string) $tier->name,
                'min_boxes' => (int) $tier->min_boxes,
                'discount_pct' => (string) BigDecimal::of((string) $tier->discount_pct)->toScale(2),
                'box_price' => (string) $boxPrice,
                'margin_pct' => $this->marginPct($boxPrice, $unitCost->multipliedBy($unitsPerBox)),
            ];
        }

        return new EdtPriceQuote(
            unitCost: (string) $unitCost->toScale(4, RoundingMode::HalfUp),
            retailUnitPrice: (string) $retailUnit,
            retailBoxPrice: (string) $retailBox,
            retailMarginPct: $this->marginPct($retailUnit, $unitCost),
            wholesale: $wholesale,
        );
    }

    /**
     * Precios de un producto con su proveedor y las escalas activas.
     */
    public function quoteForProduct(EdtProduct $product): EdtPriceQuote
    {
        $product->loadMissing('supplier:id,operation_discount_pct');

        return $this->quote(
            listPrice: (string) $product->list_price,
            unitsPerBox: (int) $product->units_per_box,
            isvPct: (string) $product->isv_pct,
            operationDiscountPct: (string) $product->supplier->operation_discount_pct,
            tiers: $this->activeTiers(),
        );
    }

    /**
     * @return Collection<int, EdtPriceTier>
     */
    public function activeTiers(): Collection
    {
        return $this->activeTiers ??= EdtPriceTier::query()
            ->where('is_active', true)
            ->orderBy('min_boxes')
            ->get(['id', 'name', 'min_boxes', 'discount_pct']);
    }

    /**
     * Olvida las escalas en memoria. Lo llama el historial cuando cambian
     * las escalas a mitad del request.
     */
    public function refreshTiers(): void
    {
        $this->activeTiers = null;
    }

    /**
     * 15 → 1.15 (add) o 0.85 (resta). Los porcentajes tienen 2 decimales,
     * así que /100 con escala 4 es exacto.
     */
    private function factorFromPct(string $pct, bool $add): BigDecimal
    {
        $fraction = BigDecimal::of($pct)->dividedBy(100, 4);

        return $add ? BigDecimal::one()->plus($fraction) : BigDecimal::one()->minus($fraction);
    }

    /**
     * Margen sobre el precio de venta: (venta − costo) / venta × 100.
     */
    private function marginPct(BigDecimal $price, BigDecimal $cost): string
    {
        if ($price->isZero()) {
            return '0.00';
        }

        return (string) $price->minus($cost)
            ->multipliedBy(100)
            ->dividedBy($price, 2, RoundingMode::HalfUp);
    }

    /**
     * @param  iterable<EdtPriceTier>  $tiers
     * @return list<EdtPriceTier>
     */
    private function sortTiers(iterable $tiers): array
    {
        $sorted = is_array($tiers) ? $tiers : iterator_to_array($tiers, false);
        usort($sorted, fn (EdtPriceTier $a, EdtPriceTier $b): int => $a->min_boxes <=> $b->min_boxes);

        return array_values($sorted);
    }
}
