<?php

namespace App\Filament\Resources\Edt\Products\Support;

use App\Services\Edt\EdtPricingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Throwable;

/**
 * Captura del precio en «Crear producto» y «Cambiar precio».
 *
 * Por defecto se escribe el precio por unidad CON ISV (el que conocen de
 * memoria) y el sistema calcula la lista SIN ISV, que es lo que se guarda
 * y de donde sale todo lo demás (EdtPricingService). Con el interruptor
 * apagado se escribe la lista sin ISV directo, p. ej. desde una factura de
 * compra o la columna "PVD - ISV" del Excel.
 *
 * Los dos campos no se guardan tal cual: quien guarda convierte con
 * listPriceFromState() a list_price.
 */
final class PriceInputFields
{
    public const INCLUDES_ISV = 'price_includes_isv';

    public const PRICE = 'price';

    public static function includesIsvToggle(): Toggle
    {
        return Toggle::make(self::INCLUDES_ISV)
            ->label('El precio incluye ISV')
            ->default(true)
            ->inline(false)
            ->live()
            ->helperText('Apágalo para escribir la lista sin ISV (p. ej. de una factura de compra).');
    }

    public static function priceInput(): TextInput
    {
        return TextInput::make(self::PRICE)
            ->label(fn (Get $get): string => self::includesIsv($get) ? 'Precio por unidad con ISV' : 'Precio de lista sin ISV')
            ->required()
            ->numeric()
            ->minValue(0.0001)
            ->maxValue(99999999)
            // Con ISV es un precio al público: centavos. Sin ISV, hasta 4
            // decimales, que es lo que guarda la BD.
            ->rule('decimal:0,2', fn (Get $get): bool => self::includesIsv($get))
            ->rule('decimal:0,4', fn (Get $get): bool => ! self::includesIsv($get))
            ->prefix('L')
            ->live(onBlur: true)
            ->helperText(function (Get $get): string {
                if (! self::includesIsv($get)) {
                    return 'El del proveedor (columna "PVD - ISV" del Excel).';
                }

                $list = self::listPriceFromInput($get(self::PRICE), true, $get('isv_pct'));

                return $list === null
                    ? 'Es el precio al detalle por unidad. El sistema calcula la lista sin ISV.'
                    : 'Lista sin ISV calculada: '.EdtPriceDisplay::listPrice($list);
            });
    }

    /**
     * Lista sin ISV a partir de lo escrito. Null si todavía no hay datos
     * válidos. $currentListPrice: la lista vigente del producto (en
     * «Cambiar precio»), para no moverla si el precio con ISV no cambió.
     */
    public static function listPriceFromInput(mixed $price, mixed $includesIsv, mixed $isvPct, ?string $currentListPrice = null): ?string
    {
        if (! is_numeric($price) || (float) $price <= 0 || ! is_numeric($isvPct)) {
            return null;
        }

        try {
            if ((bool) $includesIsv) {
                return app(EdtPricingService::class)->listPriceFromRetail((string) $price, (string) $isvPct, $currentListPrice);
            }

            return (string) BigDecimal::of((string) $price)->toScale(4, RoundingMode::HalfUp);
        } catch (Throwable) {
            // Un número a medio escribir no debe romper el formulario.
            return null;
        }
    }

    /**
     * Igual que listPriceFromInput() pero leyendo el estado ya validado de
     * un formulario (lo que reciben las páginas y la acción al guardar).
     *
     * @param  array<string, mixed>  $data
     */
    public static function listPriceFromState(array $data, ?string $currentListPrice = null): ?string
    {
        return self::listPriceFromInput(
            $data[self::PRICE] ?? null,
            $data[self::INCLUDES_ISV] ?? true,
            $data['isv_pct'] ?? null,
            $currentListPrice,
        );
    }

    private static function includesIsv(Get $get): bool
    {
        return (bool) ($get(self::INCLUDES_ISV) ?? true);
    }
}
