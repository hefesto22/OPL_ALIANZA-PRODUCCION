<?php

namespace App\Filament\Resources\Edt\Products\Tables;

use App\Filament\Resources\Edt\Products\Actions\ChangePriceAction;
use App\Filament\Resources\Edt\Products\Support\EdtPriceDisplay;
use App\Models\Edt\EdtPriceTier;
use App\Models\Edt\EdtProduct;
use App\Services\Edt\EdtPricingService;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Listado del catálogo con los precios YA calculados (EdtPricingService).
 *
 * Los precios no son columnas de la BD: se calculan por fila y se
 * memorizan en el modelo (EdtProduct::priceQuote), así que 25 filas son 25
 * cálculos en memoria + 1 consulta de escalas. Por eso esas columnas no se
 * pueden ordenar; se ordena por precio de lista, que mueve a todas igual.
 *
 * Diseño: cada celda lleva el dato principal arriba y el secundario
 * debajo en gris (producto → código; costo → lista; detalle caja → precio
 * por unidad), así la columna Producto conserva el ancho aun en una
 * laptop de 1280 px. Cifras de ancho fijo para que los montos se lean
 * en columna.
 */
class EdtProductsTable
{
    /** Cifras de ancho fijo: los montos quedan alineados por dígito. */
    private const NUMERIC_STYLE = ['style' => 'font-variant-numeric: tabular-nums'];

    public static function configure(Table $table): Table
    {
        return $table
            ->description('Precios con ISV incluido. El precio mayorista se aplica por cajas del mismo código.')
            ->columns([
                TextColumn::make('description')
                    ->label('Producto')
                    ->searchable(['code', 'description'])
                    ->sortable()
                    ->weight(FontWeight::Medium)
                    ->wrap()
                    ->grow()
                    ->description(fn (EdtProduct $record): string => self::productDetails($record)),

                TextColumn::make('supplier.code')
                    ->label('Proveedor')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('category')
                    ->label('Categoría')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('family')
                    ->label('Familia')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('units_per_box')
                    ->label('U×C')
                    ->headerTooltip('Unidades por caja')
                    ->alignCenter()
                    ->visibleFrom('md')
                    ->extraAttributes(self::NUMERIC_STYLE),

                TextColumn::make('isv_pct')
                    ->label('ISV')
                    ->badge()
                    ->alignCenter()
                    ->color(fn ($state): string => match ((int) $state) {
                        0 => 'gray',
                        18 => 'warning',
                        default => 'info',
                    })
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::isv($state)),

                // Costo por unidad arriba y, debajo, la lista del proveedor de la
                // que sale. Ordenar por lista mueve igual a todos los precios.
                TextColumn::make('unit_cost')
                    ->label('Costo unidad')
                    ->headerTooltip('Lista del proveedor menos el descuento de operación, más ISV')
                    ->state(fn (EdtProduct $record): string => $record->priceQuote()->unitCost)
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::money($state))
                    ->description(fn (EdtProduct $record): string => 'Lista '.EdtPriceDisplay::listPrice($record->list_price))
                    ->color('gray')
                    ->alignEnd()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('list_price', $direction))
                    ->visibleFrom('lg')
                    // El costo (y el margen) del EDT no es para el vendedor de ruta.
                    ->visible(fn (): bool => ! self::isSeller())
                    ->extraAttributes(self::NUMERIC_STYLE),

                TextColumn::make('retail_box_price')
                    ->label('Detalle caja')
                    ->state(fn (EdtProduct $record): string => $record->priceQuote()->retailBoxPrice)
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::money($state))
                    ->description(fn (EdtProduct $record): string => EdtPriceDisplay::money($record->priceQuote()->retailUnitPrice).' c/u')
                    ->tooltip(fn (EdtProduct $record): ?string => self::isSeller() ? null : 'Margen '.$record->priceQuote()->retailMarginPct.'%')
                    ->weight(FontWeight::SemiBold)
                    ->alignEnd()
                    ->extraAttributes(self::NUMERIC_STYLE),

                ColumnGroup::make('Mayorista por caja', self::wholesaleColumns())
                    ->alignCenter(),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('description')
            ->striped()
            ->filters([
                SelectFilter::make('supplier_id')
                    ->label('Proveedor')
                    ->relationship('supplier', 'name')
                    ->preload(),

                SelectFilter::make('category')
                    ->label('Categoría')
                    ->multiple()
                    ->options(fn (): array => EdtProduct::query()
                        ->whereNotNull('category')
                        ->distinct()
                        ->orderBy('category')
                        ->pluck('category', 'category')
                        ->all()),

                SelectFilter::make('isv_pct')
                    ->label('ISV')
                    ->options(EdtProduct::ISV_RATES),

                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
            ])
            ->recordActions([
                ChangePriceAction::make()
                    ->iconButton()
                    ->tooltip('Cambiar precio'),
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Editar'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('supplier:id,code,name,operation_discount_pct'))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    /**
     * Vendedor de ruta del EDT: ve precios de venta, no costo ni margen.
     */
    private static function isSeller(): bool
    {
        return Auth::user()?->edtSeller !== null;
    }

    /**
     * Segunda línea de la columna Producto: "28000052 · BEBIDAS · LATA".
     * Un producto inactivo lo dice ahí mismo, sin ocupar otra columna.
     */
    private static function productDetails(EdtProduct $record): string
    {
        return collect([
            $record->code,
            $record->category,
            $record->presentation,
            $record->is_active ? null : 'INACTIVO',
        ])->filter(fn (?string $part): bool => filled($part))->implode(' · ');
    }

    /**
     * Una columna por escala mayorista activa ("25+ cajas"), precio por caja.
     * El nombre de la escala y su descuento van en el tooltip del encabezado.
     *
     * @return list<TextColumn>
     */
    private static function wholesaleColumns(): array
    {
        return app(EdtPricingService::class)
            ->activeTiers()
            ->map(fn (EdtPriceTier $tier): TextColumn => TextColumn::make("wholesale_{$tier->id}")
                ->label("{$tier->min_boxes}+ cajas")
                ->headerTooltip("{$tier->name}: ".(float) $tier->discount_pct."% de descuento desde {$tier->min_boxes} cajas del mismo código")
                ->state(fn (EdtProduct $record): ?string => $record->priceQuote()->wholesaleForTier($tier->id)['box_price'] ?? null)
                ->formatStateUsing(fn ($state): string => EdtPriceDisplay::money($state))
                ->tooltip(fn (EdtProduct $record): ?string => match (true) {
                    self::isSeller() => $tier->name,
                    ($margin = $record->priceQuote()->wholesaleForTier($tier->id)['margin_pct'] ?? null) !== null => "{$tier->name} · margen {$margin}%",
                    default => null,
                })
                ->alignEnd()
                ->extraAttributes(self::NUMERIC_STYLE))
            ->values()
            ->all();
    }
}
