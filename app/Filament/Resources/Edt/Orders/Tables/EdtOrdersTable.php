<?php

namespace App\Filament\Resources\Edt\Orders\Tables;

use App\Enums\Edt\EdtOrderStatus;
use App\Enums\Edt\EdtPaymentType;
use App\Filament\Resources\Edt\Orders\EdtOrderResource;
use App\Models\Edt\EdtOrder;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Listado de preventas. Columnas de relación con eager loading (sin N+1);
 * los filtros usan los índices (bodega, estado, fecha) y (vendedor, fecha).
 */
class EdtOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'client:id,code,name',
                'seller:id,name',
                'warehouse:id,code',
            ]))
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordUrl(fn (EdtOrder $record): string => self::isSeller() && $record->status->isEditableBySeller()
                ? EdtOrderResource::getUrl('take', ['record' => $record])
                : EdtOrderResource::getUrl('view', ['record' => $record]))
            ->columns(self::isSeller() ? self::sellerColumns() : [
                TextColumn::make('number')
                    ->label('Preventa')
                    ->placeholder('BORRADOR')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (EdtOrder $record): string => $record->order_date->format('d/m/Y')),

                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->description(fn (EdtOrder $record): string => (string) $record->client?->code)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'client',
                        fn (Builder $client): Builder => $client->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"),
                    ))
                    ->wrap(),

                TextColumn::make('seller.name')
                    ->label('Vendedor')
                    ->hidden(fn (): bool => self::isSeller())
                    ->toggleable(),

                TextColumn::make('warehouse.code')
                    ->label('Bodega')
                    ->badge()
                    ->color('gray')
                    ->hidden(fn (): bool => self::isSeller())
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),

                TextColumn::make('payment_type')
                    ->label('Pago')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('lines_count')
                    ->label('Productos')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('total')
                    ->label('Total')
                    ->money('HNL')
                    ->alignEnd()
                    ->weight('bold')
                    ->description(fn (EdtOrder $record): ?string => $record->isReduced()
                        ? 'Pidió L '.number_format((float) $record->requested_total, 2)
                        : null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(EdtOrderStatus::class),

                SelectFilter::make('payment_type')
                    ->label('Pago')
                    ->options(EdtPaymentType::class),

                SelectFilter::make('seller_id')
                    ->label('Vendedor')
                    ->relationship('seller', 'name')
                    ->searchable()
                    ->preload()
                    ->hidden(fn (): bool => self::isSeller()),

                Filter::make('order_date')
                    ->label('Fecha')
                    ->schema([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('order_date', '<=', $date))),
            ]);
    }

    /**
     * El vendedor ve la lista en el celular: una tarjeta por preventa
     * (cliente, número y fecha; total y estado a la derecha).
     *
     * @return array<int, Split>
     */
    private static function sellerColumns(): array
    {
        return [
            Split::make([
                Stack::make([
                    TextColumn::make('client.name')
                        ->label('Cliente')
                        ->weight('bold')
                        ->wrap()
                        ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query
                            ->where('number', 'ilike', "%{$search}%")
                            ->orWhereHas('client', fn (Builder $client): Builder => $client->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%")))),
                    TextColumn::make('number')
                        ->label('Preventa')
                        ->state(fn (EdtOrder $record): string => ($record->number ?? 'BORRADOR').' · '.$record->order_date->format('d/m/Y'))
                        ->color('gray')
                        ->size('sm'),
                ]),
                Stack::make([
                    TextColumn::make('total')
                        ->label('Total')
                        ->money('HNL')
                        ->weight('bold')
                        ->alignEnd(),
                    TextColumn::make('status')
                        ->label('Estado')
                        ->badge()
                        ->alignEnd(),
                ])->grow(false)->space(1),
            ]),
        ];
    }

    private static function isSeller(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->edtSeller !== null;
    }
}
