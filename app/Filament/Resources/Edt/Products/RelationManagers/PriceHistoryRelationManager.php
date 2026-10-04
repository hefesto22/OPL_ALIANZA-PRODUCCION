<?php

namespace App\Filament\Resources\Edt\Products\RelationManagers;

use App\Filament\Resources\Edt\Products\Support\EdtPriceDisplay;
use App\Models\Edt\EdtProductPriceHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * Historial de precios del producto: solo lectura. Las filas las escribe el
 * sistema (EdtPriceHistoryService); aquí no se crea, edita ni borra nada.
 */
class PriceHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'priceHistory';

    protected static ?string $title = 'Historial de precios';

    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * Quien puede ver el producto ve su historial. El historial no tiene
     * Policy propia: no se gestiona, solo se consulta.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Auth::user()?->can('view', $ownerRecord) ?? false;
    }

    /**
     * La acción «Cambiar precio» del encabezado avisa con este evento para
     * que la tabla muestre la fila nueva sin recargar la página.
     */
    #[On('refresh-price-history')]
    public function refreshPriceHistory(): void
    {
        // Basta con que Livewire vuelva a renderizar el componente.
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i'),

                TextColumn::make('reason')
                    ->label('Motivo')
                    ->badge(),

                TextColumn::make('list_price')
                    ->label('Lista sin ISV')
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::listPrice($state))
                    ->alignEnd(),

                TextColumn::make('isv_pct')
                    ->label('ISV')
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::isv($state)),

                TextColumn::make('units_per_box')
                    ->label('U×C')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('operation_discount_pct')
                    ->label('Desc. operación')
                    ->suffix('%')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('unit_cost')
                    ->label('Costo unidad')
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::money($state))
                    ->alignEnd(),

                TextColumn::make('retail_unit_price')
                    ->label('Detalle unidad')
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::money($state))
                    ->alignEnd(),

                TextColumn::make('retail_box_price')
                    ->label('Detalle caja')
                    ->formatStateUsing(fn ($state): string => EdtPriceDisplay::money($state))
                    ->alignEnd(),

                TextColumn::make('wholesale_prices')
                    ->label('Mayoristas (caja)')
                    ->state(fn (EdtProductPriceHistory $record): array => collect($record->wholesale_prices)
                        ->map(fn (array $tier): string => $tier['name'].': '.EdtPriceDisplay::money($tier['box_price']))
                        ->all())
                    ->listWithLineBreaks(),

                TextColumn::make('createdBy.name')
                    ->label('Usuario')
                    ->placeholder('Sistema'),

                TextColumn::make('note')
                    ->label('Nota')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('createdBy:id,name'))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }
}
