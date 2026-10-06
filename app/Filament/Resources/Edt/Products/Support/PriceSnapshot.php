<?php

namespace App\Filament\Resources\Edt\Products\Support;

use App\Models\Edt\EdtProduct;
use App\Services\Edt\EdtPriceQuote;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Los datos que mueven el precio de un producto (lista sin ISV, costo por
 * caja, unidades por caja, ISV) junto con los precios que salen de ellos.
 * Es lo que compara la vista previa: el precio vigente contra lo que hay
 * escrito en el formulario.
 */
final class PriceSnapshot
{
    public function __construct(
        public readonly EdtPriceQuote $quote,
        public readonly string $listPrice,
        public readonly string $boxCost,
        public readonly int $unitsPerBox,
        public readonly string $isvPct,
    ) {}

    public static function fromProduct(EdtProduct $product): self
    {
        return new self(
            quote: $product->priceQuote(),
            listPrice: (string) $product->list_price,
            boxCost: (string) $product->box_cost,
            unitsPerBox: (int) $product->units_per_box,
            isvPct: (string) (int) $product->isv_pct,
        );
    }

    /**
     * Desde lo que hay escrito en un formulario. Null si todavía no alcanza
     * para calcular.
     */
    public static function fromInput(mixed $listPrice, mixed $unitsPerBox, mixed $isvPct, mixed $boxCost): ?self
    {
        $quote = EdtPriceDisplay::quoteFromInput($listPrice, $unitsPerBox, $isvPct, $boxCost);

        if ($quote === null) {
            return null;
        }

        return new self(
            quote: $quote,
            listPrice: (string) BigDecimal::of((string) $listPrice)->toScale(4, RoundingMode::HalfUp),
            boxCost: $quote->boxCost,
            unitsPerBox: (int) $unitsPerBox,
            isvPct: (string) (int) $isvPct,
        );
    }
}
