<?php

namespace App\Filament\Resources\Edt\Products\Support;

use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtSupplier;
use App\Services\Edt\EdtPricingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Captura del precio y del costo en «Crear producto» y «Cambiar precio».
 *
 * Se escriben dos datos: el PRECIO DE VENTA por unidad y el COSTO POR CAJA.
 * Todo lo demás (lista sin ISV, caja al detalle, mayoristas, márgenes) lo
 * calcula EdtPricingService.
 *
 * Un solo selector «Con ISV / Sin ISV» decide cómo se escriben los dos. Por
 * defecto, con ISV (el precio que conocen de memoria). Lo que se guarda:
 * la lista SIN ISV (list_price) y el costo por caja CON ISV (box_cost).
 * Cambiar el selector convierte lo que ya estaba escrito (30.00 ↔ 26.087):
 * cambia la forma de escribir, no el precio ni el costo.
 *
 * El costo se SUGIERE con el descuento habitual del proveedor (15%): lista
 * − 15% + ISV, por caja, como lo calculaba el Excel. Si el campo está vacío
 * se llena solo; si no, la sugerencia queda a la vista debajo.
 *
 * Los campos no se guardan tal cual: quien guarda convierte con
 * listPriceFromState() y boxCostFromState().
 */
final class PriceInputFields
{
    public const INCLUDES_ISV = 'price_includes_isv';

    public const PRICE = 'price';

    public const COST = 'cost';

    /** Montos grandes y de ancho fijo en los dos campos que se escriben. */
    private const BIG_INPUT = ['style' => 'font-size: 1.125rem; font-weight: 600; font-variant-numeric: tabular-nums'];

    public static function includesIsvToggle(): ToggleButtons
    {
        return ToggleButtons::make(self::INCLUDES_ISV)
            ->label('Precio y costo que vas a escribir')
            ->boolean('Con ISV', 'Sin ISV')
            ->icons([1 => Heroicon::OutlinedTag, 0 => Heroicon::OutlinedDocumentText])
            ->colors([1 => 'primary', 0 => 'primary'])
            ->grouped()
            ->default(true)
            ->required()
            ->live()
            ->afterStateUpdated(function (mixed $state, Get $get, Set $set, mixed $record): void {
                $current = $record instanceof EdtProduct ? $record : null;

                $set(self::PRICE, self::convertTypedPrice(
                    $get(self::PRICE),
                    toIncludesIsv: (bool) $state,
                    isvPct: $get('isv_pct'),
                    currentListPrice: $current ? (string) $current->list_price : null,
                ));

                $set(self::COST, self::convertTypedCost(
                    $get(self::COST),
                    toIncludesIsv: (bool) $state,
                    isvPct: $get('isv_pct'),
                    currentBoxCost: $current ? (string) $current->box_cost : null,
                ));
            });
    }

    public static function priceInput(): TextInput
    {
        return TextInput::make(self::PRICE)
            ->label(fn (Get $get): string => self::includesIsv($get) ? 'Precio de venta por unidad, con ISV' : 'Lista por unidad, sin ISV')
            ->required()
            ->numeric()
            ->minValue(0.0001)
            ->maxValue(99999999)
            // Con ISV es un precio al público: centavos. Sin ISV, hasta 4
            // decimales, que es lo que guarda la BD.
            ->rule('decimal:0,2', fn (Get $get): bool => self::includesIsv($get))
            ->rule('decimal:0,4', fn (Get $get): bool => ! self::includesIsv($get))
            ->prefix('L')
            ->extraInputAttributes(self::BIG_INPUT)
            ->live(debounce: 600)
            ->afterStateUpdated(fn (Get $get, Set $set, mixed $record) => self::fillSuggestedCostIfBlank($get, $set, $record))
            ->helperText(fn (Get $get): string => self::includesIsv($get)
                ? 'El precio al público. El sistema calcula la lista sin ISV.'
                : 'Como viene en la lista del proveedor (columna "PVD - ISV" del Excel).');
    }

    public static function costInput(): TextInput
    {
        return TextInput::make(self::COST)
            ->label(fn (Get $get): string => self::includesIsv($get) ? 'Costo por caja, con ISV' : 'Costo por caja, sin ISV')
            ->required()
            ->numeric()
            ->minValue(0.0001)
            ->maxValue(9999999999)
            ->rule('decimal:0,4')
            ->prefix('L')
            ->extraInputAttributes(self::BIG_INPUT)
            ->live(debounce: 600)
            ->helperText(function (Get $get, mixed $record): string {
                $suggested = self::suggestedCost($get, $record);

                return $suggested === null
                    ? 'Lo que le cuesta la caja al EDT.'
                    : 'Lo que le cuesta la caja al EDT. Con el descuento del proveedor sería L '.$suggested.'.';
            });
    }

    /**
     * ISV a la vista (EXENTO · 15% · 18%) en lugar de una lista desplegable.
     */
    public static function isvButtons(): ToggleButtons
    {
        return ToggleButtons::make('isv_pct')
            ->label('ISV')
            ->options(EdtProduct::ISV_RATES)
            ->colors(array_fill_keys(array_keys(EdtProduct::ISV_RATES), 'primary'))
            ->grouped()
            ->required()
            ->live()
            ->afterStateUpdated(fn (Get $get, Set $set, mixed $record) => self::fillSuggestedCostIfBlank($get, $set, $record));
    }

    public static function unitsPerBoxInput(): TextInput
    {
        return TextInput::make('units_per_box')
            ->label('Unidades por caja')
            ->required()
            ->integer()
            ->minValue(1)
            ->maxValue(100000)
            ->live(onBlur: true)
            ->afterStateUpdated(fn (Get $get, Set $set, mixed $record) => self::fillSuggestedCostIfBlank($get, $set, $record));
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
     * Costo por caja CON ISV (lo que se guarda) a partir de lo escrito. Null
     * si todavía no hay datos válidos. $currentBoxCost: el costo vigente, para
     * no moverlo por redondeo si se escribió sin ISV y es el mismo.
     */
    public static function boxCostFromInput(mixed $cost, mixed $includesIsv, mixed $isvPct, ?string $currentBoxCost = null): ?string
    {
        if (! is_numeric($cost) || (float) $cost <= 0 || ! is_numeric($isvPct)) {
            return null;
        }

        try {
            if ((bool) $includesIsv) {
                return (string) BigDecimal::of((string) $cost)->toScale(4, RoundingMode::HalfUp);
            }

            $pricing = app(EdtPricingService::class);

            if ($currentBoxCost !== null
                && BigDecimal::of($pricing->withoutIsv($currentBoxCost, (string) $isvPct))->isEqualTo((string) $cost)) {
                return (string) BigDecimal::of($currentBoxCost)->toScale(4, RoundingMode::HalfUp);
            }

            return $pricing->withIsv((string) $cost, (string) $isvPct);
        } catch (Throwable) {
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

    /**
     * @param  array<string, mixed>  $data
     */
    public static function boxCostFromState(array $data, ?string $currentBoxCost = null): ?string
    {
        return self::boxCostFromInput(
            $data[self::COST] ?? null,
            $data[self::INCLUDES_ISV] ?? true,
            $data['isv_pct'] ?? null,
            $currentBoxCost,
        );
    }

    /**
     * El precio escrito, pasado a la otra forma: con ISV → lista sin ISV, o
     * lista → precio con ISV. Si todavía no hay un número válido, queda igual.
     */
    public static function convertTypedPrice(mixed $price, bool $toIncludesIsv, mixed $isvPct, ?string $currentListPrice = null): mixed
    {
        if (! is_numeric($price) || (float) $price <= 0 || ! is_numeric($isvPct)) {
            return $price;
        }

        try {
            $pricing = app(EdtPricingService::class);

            if ($toIncludesIsv) {
                return $pricing->retailUnitPrice((string) $price, (string) $isvPct);
            }

            return EdtPriceDisplay::plainAmount($pricing->listPriceFromRetail((string) $price, (string) $isvPct, $currentListPrice));
        } catch (Throwable) {
            return $price;
        }
    }

    /**
     * El costo escrito, pasado a la otra forma (con ISV ↔ sin ISV).
     */
    public static function convertTypedCost(mixed $cost, bool $toIncludesIsv, mixed $isvPct, ?string $currentBoxCost = null): mixed
    {
        if (! is_numeric($cost) || (float) $cost <= 0 || ! is_numeric($isvPct)) {
            return $cost;
        }

        try {
            $pricing = app(EdtPricingService::class);

            if ($toIncludesIsv) {
                return EdtPriceDisplay::plainAmount((string) self::boxCostFromInput($cost, false, $isvPct, $currentBoxCost));
            }

            return EdtPriceDisplay::plainAmount($pricing->withoutIsv((string) $cost, (string) $isvPct));
        } catch (Throwable) {
            return $cost;
        }
    }

    /**
     * Con el campo de costo vacío, lo llena con la sugerencia (cuando ya hay
     * precio, unidades por caja e ISV para calcularla).
     */
    public static function fillSuggestedCostIfBlank(Get $get, Set $set, mixed $record): void
    {
        if (filled($get(self::COST))) {
            return;
        }

        $suggested = self::suggestedCost($get, $record, plain: true);

        if ($suggested !== null) {
            $set(self::COST, $suggested);
        }
    }

    /**
     * Costo por caja sugerido (lista − descuento del proveedor + ISV, por
     * caja), en la forma que esté elegida (con o sin ISV). $plain: para el
     * campo (26.087); si no, formateado para leer (1,427.15).
     */
    private static function suggestedCost(Get $get, mixed $record, bool $plain = false): ?string
    {
        $includesIsv = self::includesIsv($get);
        $isvPct = $get('isv_pct');
        $units = $get('units_per_box');
        $list = self::listPriceFromInput(
            $get(self::PRICE),
            $includesIsv,
            $isvPct,
            $record instanceof EdtProduct ? (string) $record->list_price : null,
        );
        $supplierId = $get('supplier_id') ?? ($record instanceof EdtProduct ? $record->supplier_id : null);

        if ($list === null || ! is_numeric($units) || (int) $units < 1 || blank($supplierId)) {
            return null;
        }

        $discount = EdtSupplier::query()->whereKey($supplierId)->value('operation_discount_pct');

        if ($discount === null) {
            return null;
        }

        try {
            $pricing = app(EdtPricingService::class);
            $withIsv = $pricing->boxCostFromList($list, (int) $units, (string) $isvPct, (string) $discount);
            $amount = $includesIsv ? $withIsv : $pricing->withoutIsv($withIsv, (string) $isvPct);
        } catch (Throwable) {
            return null;
        }

        return $plain
            ? EdtPriceDisplay::plainAmount((string) BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp))
            : number_format((float) $amount, 2);
    }

    private static function includesIsv(Get $get): bool
    {
        return (bool) ($get(self::INCLUDES_ISV) ?? true);
    }
}
