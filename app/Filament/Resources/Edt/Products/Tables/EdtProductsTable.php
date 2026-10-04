<?php

namespace App\Filament\Resources\Edt\Products\Tables;

use App\Filament\Resources\Edt\Products\Actions\ChangePriceAction;
use App\Filament\Resources\Edt\Products\Support\EdtPriceDisplay;
use App\Models\Edt\EdtPriceTier;
use App\Models\Edt\EdtProduct;
use App\Services\Edt\EdtPricingService;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado del catálogo con los precios YA calculados (EdtPricingService).
 *
 * Los precios no son columnas de la BD: se calculan por fila y se
 * memorizan en el modelo (EdtProduct::priceQuote), así que 25 filas son 25
 * cálculos en memoria + 1 consulta de escalas. Por eso esas columnas no se
 * pueden ordenar; se ordena por precio de lista, que mueve a todas igual.
 */
class EdtProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

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
                    ->alignCenter(),

                TextColumn::make('isv_pct')
                    ->label('ISV')
                    ->badge()
                    ->color(fn ($state): string => (float) $state > 0 ? 'info' : 'gray')
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::isv($state)),

                TextColumn::make('list_price')
                    ->label('Lista sin ISV')
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::listPrice($state))
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('unit_cost')
                    ->label('Costo unidad')
                    ->state(fn (EdtProduct $record): string => $record->priceQuote()->unitCost)
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::money($state))
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('retail_unit_price')
                    ->label('Detalle unidad')
                    ->state(fn (EdtProduct $record): string => $record->priceQuote()->retailUnitPrice)
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::money($state))
                    ->alignEnd(),

                TextColumn::make('retail_box_price')
                    ->label('Detalle caja')
                    ->state(fn (EdtProduct $record): string => $record->priceQuote()->retailBoxPrice)
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::money($state))
                    ->alignEnd(),

                ...self::wholesaleColumns(),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->defaultSort('description')
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
                ChangePriceAction::make(),
                EditAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('supplier:id,code,name,operation_discount_pct'))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    /**
     * Una columna por escala mayorista activa ("MAYORISTA 1 (25+ CJ)"), precio por caja.
     *
     * @return list<TextColumn>
     */
    private static function wholesaleColumns(): array
    {
        return app(EdtPricingService::class)
            ->activeTiers()
            ->map(fn (EdtPriceTier $tier): TextColumn => TextColumn::make("wholesale_{$tier->id}")
                ->label("{$tier->name} ({$tier->min_boxes}+ CJ)")
                ->state(fn (EdtProduct $record): ?string => $record->priceQuote()->wholesaleForTier($tier->id)['box_price'] ?? null)
                ->formatStateUsing(fn ($state): string => EdtPriceDisplay::money($state))
                ->alignEnd())
            ->values()
            ->all();
    }
}
