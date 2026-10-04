<?php

namespace App\Filament\Resources\Edt\Products\Support;

use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtSupplier;
use App\Services\Edt\EdtPriceQuote;
use App\Services\Edt\EdtPricingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\HtmlString;
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

    public static function isv(string|int|float|null $pct): string
    {
        return EdtProduct::ISV_RATES[(string) (int) $pct] ?? ((string) $pct.'%');
    }

    /**
     * Cotiza desde lo que hay escrito en un formulario. Null si todavía no
     * hay datos válidos para calcular.
     */
    public static function quoteFromInput(mixed $supplierId, mixed $listPrice, mixed $unitsPerBox, mixed $isvPct): ?EdtPriceQuote
    {
        if (! is_numeric($listPrice) || (float) $listPrice <= 0
            || ! is_numeric($unitsPerBox) || (int) $unitsPerBox < 1
            || ! is_numeric($isvPct) || blank($supplierId)) {
            return null;
        }

        $discount = EdtSupplier::query()->whereKey($supplierId)->value('operation_discount_pct');

        if ($discount === null) {
            return null;
        }

        try {
            $pricing = app(EdtPricingService::class);

            return $pricing->quote(
                // Igual que el cast del modelo: 4 decimales.
                listPrice: (string) BigDecimal::of((string) $listPrice)->toScale(4, RoundingMode::HalfUp),
                unitsPerBox: (int) $unitsPerBox,
                isvPct: (string) $isvPct,
                operationDiscountPct: (string) $discount,
                tiers: $pricing->activeTiers(),
            );
        } catch (Throwable) {
            // Un número a medio escribir no debe romper el formulario.
            return null;
        }
    }

    /**
     * Tabla chica de precios para los formularios. Con $before muestra la
     * columna "Actual" al lado de "Nuevo" (acción Cambiar precio).
     */
    public static function previewTable(?EdtPriceQuote $quote, ?EdtPriceQuote $before = null): HtmlString|string
    {
        if ($quote === null) {
            return 'Completa proveedor, precio de lista, unidades por caja e ISV para ver los precios.';
        }

        $rows = [
            ['Costo por unidad', $before?->unitCost, $quote->unitCost],
            ['Detalle por unidad', $before?->retailUnitPrice, $quote->retailUnitPrice],
            ['Detalle por caja', $before?->retailBoxPrice, $quote->retailBoxPrice],
        ];

        foreach ($quote->wholesale as $tier) {
            $previous = $before?->wholesaleForTier((int) $tier['tier_id']);
            $rows[] = [
                $tier['name'].' (caja, desde '.$tier['min_boxes'].')',
                $previous['box_price'] ?? null,
                $tier['box_price'],
            ];
        }

        $head = $before
            ? '<tr><th style="text-align:left">Precio (con ISV)</th><th style="text-align:right">Actual</th><th style="text-align:right">Nuevo</th></tr>'
            : '<tr><th style="text-align:left">Precio (con ISV)</th><th style="text-align:right">Monto</th></tr>';

        $body = '';
        foreach ($rows as [$label, $old, $new]) {
            $changed = $before && $old !== null && BigDecimal::of($old)->isEqualTo($new) === false;
            $newCell = '<td style="text-align:right'.($changed ? ';font-weight:700' : '').'">'.e(self::money($new)).'</td>';
            $body .= '<tr><td>'.e($label).'</td>'
                .($before ? '<td style="text-align:right">'.e(self::money($old)).'</td>' : '')
                .$newCell.'</tr>';
        }

        $margin = '<p style="margin-top:.5rem;font-size:.85em;opacity:.8">Margen al detalle: '
            .e($quote->retailMarginPct).'%'
            .collect($quote->wholesale)->map(fn (array $t): string => ' · '.e($t['name']).': '.e($t['margin_pct']).'%')->implode('')
            .'</p>';

        return new HtmlString(
            '<table style="width:100%;font-size:.9em;border-collapse:collapse"><thead>'.$head.'</thead><tbody>'.$body.'</tbody></table>'.$margin
        );
    }
}
