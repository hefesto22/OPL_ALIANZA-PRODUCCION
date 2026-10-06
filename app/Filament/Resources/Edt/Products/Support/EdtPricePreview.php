<?php

namespace App\Filament\Resources\Edt\Products\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\HtmlString;

/**
 * Vista previa de precios de los formularios del EDT («Crear producto»,
 * «Editar» y «Cambiar precio»).
 *
 * Arma los datos (qué se muestra, qué cambió y hacia dónde) y la vista
 * resources/views/filament/edt/price-preview.blade.php solo los pinta. Con
 * $before compara contra el precio vigente: columnas Actual / Nuevo /
 * Diferencia.
 *
 * Colores de la diferencia: verde cuando le conviene al EDT (sube un precio
 * de venta o un margen, baja el costo) y rojo al revés.
 *
 * @phpstan-type PreviewRow array{label: string, sub: string|null, old: string|null, new: string, delta: string|null, trend: 'up'|'down'|'same'|null, tone: 'good'|'bad'|'same'|null}
 */
final class EdtPricePreview
{
    private const VIEW = 'filament.edt.price-preview';

    private const MINUS = "\u{2212}";

    public static function render(?PriceSnapshot $new, ?PriceSnapshot $before = null): HtmlString
    {
        return new HtmlString(view(self::VIEW, self::data($new, $before))->render());
    }

    /**
     * @return array<string, mixed>
     */
    public static function data(?PriceSnapshot $new, ?PriceSnapshot $before = null): array
    {
        if ($new === null) {
            return ['empty' => true];
        }

        $quote = $new->quote;
        $old = $before?->quote;

        $wholesale = [];
        $margins = [self::percentRow('Margen al detalle', $old?->retailMarginPct, $quote->retailMarginPct)];

        foreach ($quote->wholesale as $tier) {
            $previous = $old?->wholesaleForTier((int) $tier['tier_id']);
            $wholesale[] = self::moneyRow(
                $tier['name'],
                'Desde '.$tier['min_boxes'].' cajas · '.self::plainPct($tier['discount_pct']).' de descuento',
                $previous['box_price'] ?? null,
                $tier['box_price'],
            );
            $margins[] = self::percentRow('Margen '.$tier['name'], $previous['margin_pct'] ?? null, $tier['margin_pct']);
        }

        $listChanged = $before !== null && ! BigDecimal::of($before->listPrice)->isEqualTo($new->listPrice);

        return [
            'empty' => false,
            'compare' => $before !== null,
            'headline' => self::moneyRow('Detalle por unidad', null, $old?->retailUnitPrice, $quote->retailUnitPrice, withPercent: true),
            'listPrice' => EdtPriceDisplay::listPrice($new->listPrice),
            'previousListPrice' => $listChanged ? EdtPriceDisplay::listPrice($before->listPrice) : null,
            'changes' => self::dataChanges($new, $before),
            // Vender la caja al detalle por menos de lo que cuesta.
            'belowCost' => BigDecimal::of($quote->retailBoxPrice)->isLessThan($quote->boxCost),
            'groups' => array_values(array_filter([
                [
                    'title' => 'Al detalle',
                    'rows' => [
                        self::moneyRow('Por unidad', null, $old?->retailUnitPrice, $quote->retailUnitPrice),
                        self::moneyRow('Por caja', $new->unitsPerBox.' unidades', $old?->retailBoxPrice, $quote->retailBoxPrice),
                    ],
                ],
                $wholesale === [] ? null : ['title' => 'Mayorista · por caja', 'rows' => $wholesale],
                [
                    'title' => 'Costo y margen',
                    'rows' => [
                        self::moneyRow('Costo por caja', 'Lo que paga el EDT, con ISV', $old?->boxCost, $quote->boxCost, costLike: true),
                        self::moneyRow('Costo por unidad', null, $old?->unitCost, $quote->unitCost, costLike: true),
                        ...$margins,
                    ],
                ],
            ])),
        ];
    }

    /**
     * Fila de un monto en lempiras, comparado a centavos (lo que se ve).
     *
     * @return PreviewRow
     */
    private static function moneyRow(string $label, ?string $sub, ?string $old, string $new, bool $withPercent = false, bool $costLike = false): array
    {
        $newValue = BigDecimal::of($new)->toScale(2, RoundingMode::HalfUp);
        $row = ['label' => $label, 'sub' => $sub, 'old' => null, 'new' => EdtPriceDisplay::money($new), 'delta' => null, 'trend' => null, 'tone' => null];

        if ($old === null) {
            return $row;
        }

        $oldValue = BigDecimal::of($old)->toScale(2, RoundingMode::HalfUp);
        $diff = $newValue->minus($oldValue);
        $row['old'] = EdtPriceDisplay::money($old);

        if ($diff->isZero()) {
            return [...$row, 'trend' => 'same', 'tone' => 'same'];
        }

        $delta = self::sign($diff).'L '.number_format((float) (string) $diff->abs(), 2);

        if ($withPercent && ! $oldValue->isZero()) {
            $percent = $diff->multipliedBy(100)->dividedBy($oldValue, 2, RoundingMode::HalfUp);
            $delta .= ' · '.self::sign($percent).$percent->abs().'%';
        }

        return [...$row, 'delta' => $delta, ...self::trend($diff, $costLike)];
    }

    /**
     * Fila de un margen; la diferencia va en puntos porcentuales.
     *
     * @return PreviewRow
     */
    private static function percentRow(string $label, ?string $old, string $new): array
    {
        $row = ['label' => $label, 'sub' => null, 'old' => null, 'new' => $new.'%', 'delta' => null, 'trend' => null, 'tone' => null];

        if ($old === null) {
            return $row;
        }

        $diff = BigDecimal::of($new)->minus($old);
        $row['old'] = $old.'%';

        if ($diff->isZero()) {
            return [...$row, 'trend' => 'same', 'tone' => 'same'];
        }

        return [...$row, 'delta' => self::sign($diff).$diff->abs()->toScale(2).' pts', ...self::trend($diff, costLike: false)];
    }

    /**
     * @return array{trend: 'up'|'down', tone: 'good'|'bad'}
     */
    private static function trend(BigDecimal $diff, bool $costLike): array
    {
        $up = $diff->isPositive();

        return ['trend' => $up ? 'up' : 'down', 'tone' => $up !== $costLike ? 'good' : 'bad'];
    }

    /**
     * Cambios en los datos del producto que mueven todos los precios.
     *
     * @return list<string>
     */
    private static function dataChanges(PriceSnapshot $new, ?PriceSnapshot $before): array
    {
        if ($before === null) {
            return [];
        }

        $changes = [];

        if ($before->isvPct !== $new->isvPct) {
            $changes[] = 'ISV '.EdtPriceDisplay::isv($before->isvPct).' → '.EdtPriceDisplay::isv($new->isvPct);
        }

        if ($before->unitsPerBox !== $new->unitsPerBox) {
            $changes[] = 'Unidades por caja '.$before->unitsPerBox.' → '.$new->unitsPerBox;
        }

        return $changes;
    }

    private static function sign(BigDecimal $value): string
    {
        return $value->isNegative() ? self::MINUS : '+';
    }

    /**
     * "3.00" → "3%", "2.50" → "2.5%".
     */
    private static function plainPct(string $pct): string
    {
        return rtrim(rtrim((string) BigDecimal::of($pct)->toScale(2), '0'), '.').'%';
    }
}
