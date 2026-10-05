<?php

namespace App\Filament\Resources\Edt\Routes\Tables;

use App\Filament\Resources\Edt\Routes\Actions\RouteAssignmentActions;
use App\Models\Edt\EdtRoute;
use App\Models\Geo\Municipality;
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
 * Listado de rutas con sus municipios y el vendedor vigente. Ya llega
 * filtrado por bodega (EdtRouteResource::getEloquentQuery).
 */
class EdtRoutesTable
{
    /** Municipios que se listan en la celda; el resto va como "+N". */
    private const MUNICIPALITIES_SHOWN = 3;

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Ruta')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Medium)
                    ->grow()
                    ->description(fn (EdtRoute $record): string => collect([
                        $record->visitDaysLabel(),
                        $record->is_active ? null : 'INACTIVA',
                    ])->filter()->implode(' · ')),

                TextColumn::make('warehouse.code')
                    ->label('Bodega')
                    ->badge()
                    ->color('gray')
                    ->tooltip(fn (EdtRoute $record): ?string => $record->warehouse?->name)
                    ->alignCenter(),

                TextColumn::make('municipalities_list')
                    ->label('Municipios')
                    ->state(fn (EdtRoute $record): string => self::municipalitiesSummary($record))
                    ->tooltip(fn (EdtRoute $record): string => $record->municipalities
                        ->map(fn (Municipality $municipality): string => $municipality->name)
                        ->implode(', '))
                    ->wrap(),

                TextColumn::make('currentAssignment.seller.name')
                    ->label('Vendedor')
                    ->placeholder('Sin vendedor')
                    ->description(fn (EdtRoute $record): ?string => $record->currentAssignment
                        ? 'desde '.$record->currentAssignment->starts_on->format('d/m/Y')
                        : null),

                IconColumn::make('is_active')
                    ->label('Activa')
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
                    ->placeholder('Todas')
                    ->trueLabel('Activas')
                    ->falseLabel('Inactivas'),
            ])
            ->recordActions([
                RouteAssignmentActions::assign()
                    ->iconButton()
                    ->tooltip('Asignar vendedor'),
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Editar'),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'warehouse:id,code,name',
                'municipalities:id,name',
                'currentAssignment.seller:id,name',
            ]))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    /**
     * "SANTA ROSA DE COPÁN, DOLORES, CUCUYAGUA +2".
     */
    private static function municipalitiesSummary(EdtRoute $route): string
    {
        $names = $route->municipalities->pluck('name')->sort()->values();
        $shown = $names->take(self::MUNICIPALITIES_SHOWN)->implode(', ');
        $rest = $names->count() - self::MUNICIPALITIES_SHOWN;

        return $rest > 0 ? "{$shown} +{$rest}" : $shown;
    }
}
