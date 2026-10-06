<?php

namespace App\Filament\Resources\Edt\Products\Support;

use App\Models\Edt\EdtProduct;
use App\Services\Edt\EdtPriceQuote;
use App\Services\Edt\EdtPricingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Throwable;

/**
 * Formato de precios del EDT para las pantallas (formato es_HN: L 1,087.92).
 */
final class EdtPriceDisplay
{
    public static function money(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return 'L '.number_format((float) $value, 2);
    }

    /**
     * El precio de lista lleva hasta 4 decimales, pero casi siempre son 2:
     * "17.6000" → "L 17.60", "8.6957" → "L 8.6957".
     */
    public static function listPrice(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $decimal = BigDecimal::of((string) $value)->toScale(4);
        $twoPlaces = $decimal->toScale(2, RoundingMode::Down);
        $places = $decimal->isEqualTo($twoPlaces) ? 2 : 4;

        return 'L '.number_format((float) (string) $decimal, $places);
    }

    /**
     * Monto para un campo editable, sin ceros de más: "1427.1500" → "1427.15",
     * "892.2964" igual.
     */
    public static function plainAmount(string $value): string
    {
        [$whole, $fraction] = explode('.', (string) BigDecimal::of($value)) + [1 => ''];

        return $whole.'.'.str_pad(rtrim($fraction, '0'), 2, '0');
    }

    public static function isv(string|int|float|null $pct): string
    {
        return EdtProduct::ISV_RATES[(string) (int) $pct] ?? ((string) $pct.'%');
    }

    /**
     * Cotiza desde lo que hay escrito en un formulario. Null si todavía no
     * hay datos válidos para calcular. $boxCost: costo por caja CON ISV.
     */
    public static function quoteFromInput(mixed $listPrice, mixed $unitsPerBox, mixed $isvPct, mixed $boxCost): ?EdtPriceQuote
    {
        if (! is_numeric($listPrice) || (float) $listPrice <= 0
            || ! is_numeric($unitsPerBox) || (int) $unitsPerBox < 1
            || ! is_numeric($isvPct)
            || ! is_numeric($boxCost) || (float) $boxCost <= 0) {
            return null;
        }

        try {
            $pricing = app(EdtPricingService::class);

            return $pricing->quote(
                // Igual que los casts del modelo: 4 decimales.
                listPrice: (string) BigDecimal::of((string) $listPrice)->toScale(4, RoundingMode::HalfUp),
                unitsPerBox: (int) $unitsPerBox,
                isvPct: (string) $isvPct,
                boxCost: (string) BigDecimal::of((string) $boxCost)->toScale(4, RoundingMode::HalfUp),
                tiers: $pricing->activeTiers(),
            );
        } catch (Throwable) {
            // Un número a medio escribir no debe romper el formulario.
            return null;
        }
    }
}
