<?php

namespace App\Services\Edt;

/**
 * Precios calculados de un producto del EDT (todos con ISV incluido).
 *
 * Montos como string decimal (nunca float): unitCost con 4 decimales y el
 * resto con 2. Lo produce EdtPricingService; no se construye a mano.
 */
final class EdtPriceQuote
{
    /**
     * $wholesale: escalas ordenadas de menor a mayor cantidad de cajas.
     *
     * @param  list<array{tier_id: int|null, name: string, min_boxes: int, discount_pct: string, box_price: string, margin_pct: string}>  $wholesale
     */
    public function __construct(
        public readonly string $unitCost,
        public readonly string $retailUnitPrice,
        public readonly string $retailBoxPrice,
        public readonly string $retailMarginPct,
        public readonly array $wholesale,
    ) {}

    /**
     * La escala que aplica a N cajas DEL MISMO CÓDIGO: la de mayor
     * min_boxes que no pase de N. Null si no llega a ninguna (va a detalle).
     *
     * @return array{tier_id: int|null, name: string, min_boxes: int, discount_pct: string, box_price: string, margin_pct: string}|null
     */
    public function wholesaleForBoxes(int $boxes): ?array
    {
        $applicable = null;

        foreach ($this->wholesale as $tier) {
            if ($tier['min_boxes'] <= $boxes) {
                $applicable = $tier;
            }
        }

        return $applicable;
    }

    /**
     * @return array{tier_id: int|null, name: string, min_boxes: int, discount_pct: string, box_price: string, margin_pct: string}|null
     */
    public function wholesaleForTier(int $tierId): ?array
    {
        foreach ($this->wholesale as $tier) {
            if ($tier['tier_id'] === $tierId) {
                return $tier;
            }
        }

        return null;
    }
}
