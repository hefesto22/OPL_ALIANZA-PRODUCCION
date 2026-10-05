<?php

namespace App\Filament\Resources\Edt\Sellers\Tables;

use App\Models\Edt\EdtRouteAssignment;
use App\Models\Edt\EdtSeller;
use App\Models\Warehouse;
use App\Support\WarehouseScope;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de vendedores: ya llega filtrado por bodega
 * (EdtSellerResource::getEloquentQuery).
 */
class EdtSellersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Vendedor')
                    ->searchable(['code', 'name'])
                    ->sortable()
                    ->weight(FontWeight::Medium)
                    ->grow()
                    ->description(fn (EdtSeller $record): string => collect([
                        $record->code,
                        $record->user?->email,
                        $record->is_active ? null : 'INACTIVO',
                    ])->filter()->implode(' · ')),

                TextColumn::make('warehouse.code')
                    ->label('Bodega')
                    ->badge()
                    ->color('gray')
                    ->tooltip(fn (EdtSeller $record): ?string => $record->warehouse?->name)
                    ->alignCenter(),

                TextColumn::make('current_routes')
                    ->label('Rutas vigentes')
                    ->state(fn (EdtSeller $record): array => $record->currentAssignments
                        ->map(fn (EdtRouteAssignment $assignment): ?string => $assignment->route?->name)
                        ->filter()
                        ->values()
                        ->all())
                    ->badge()
                    ->placeholder('Sin rutas'),

                TextColumn::make('phone')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->visibleFrom('md'),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->striped()
            ->filters([
                SelectFilter::make('warehouse_id')
                    ->label('Bodega')
                    ->options(fn (): array => WarehouseScope::apply(Warehouse::query(), 'id')
                        ->orderBy('code')
                        ->get(['id', 'code', 'name'])
                        ->mapWithKeys(fn (Warehouse $warehouse): array => [$warehouse->id => "{$warehouse->code} · {$warehouse->name}"])
                        ->all()),

                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Activos')
                    ->falseLabel('Inactivos'),
            ])
            ->recordActions([
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Editar'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'warehouse:id,code,name',
                'user:id,email',
                'currentAssignments.route:id,name',
            ]))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }
}
