<?php

namespace App\Filament\Resources\Edt\Clients\Tables;

use App\Models\Edt\EdtClient;
use App\Models\Geo\Department;
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
 * Listado de clientes del EDT, con el mismo estilo que el de productos:
 * dato principal arriba y el secundario debajo en gris.
 *
 * Ya llega filtrado por bodega (EdtClientResource::getEloquentQuery); el
 * filtro de Bodega solo ofrece las bodegas del usuario.
 */
class EdtClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Cliente')
                    ->searchable(['code', 'name', 'business_name', 'rtn', 'phone'])
                    ->sortable()
                    ->weight(FontWeight::Medium)
                    ->wrap()
                    ->grow()
                    ->description(fn (EdtClient $record): string => self::clientDetails($record)),

                TextColumn::make('warehouse.code')
                    ->label('Bodega')
                    ->badge()
                    ->color('gray')
                    ->tooltip(fn (EdtClient $record): ?string => $record->warehouse?->name)
                    ->alignCenter(),

                TextColumn::make('municipality.name')
                    ->label('Zona')
                    ->description(fn (EdtClient $record): ?string => $record->department?->name),

                TextColumn::make('business_type')
                    ->label('Tipo')
                    ->placeholder('—')
                    ->toggleable()
                    ->visibleFrom('lg'),

                TextColumn::make('phone')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable()
                    ->visibleFrom('md'),

                TextColumn::make('credit_enabled')
                    ->label('Crédito')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (EdtClient $record): string => self::creditLabel($record))
                    ->description(fn (EdtClient $record): ?string => $record->credit_enabled && $record->credit_days !== null
                        ? "{$record->credit_days} días"
                        : null),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
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

                SelectFilter::make('department_id')
                    ->label('Departamento')
                    ->options(fn (): array => Department::options())
                    ->searchable(),

                SelectFilter::make('municipality_id')
                    ->label('Municipio')
                    ->multiple()
                    ->searchable()
                    // Varios municipios se llaman igual (SAN JOSÉ, CONCEPCIÓN…):
                    // la opción lleva el departamento para distinguirlos.
                    ->options(fn (): array => Municipality::query()
                        ->with('department:id,name')
                        ->orderBy('name')
                        ->get(['id', 'name', 'department_id'])
                        ->mapWithKeys(fn (Municipality $municipality): array => [
                            $municipality->id => "{$municipality->name} · {$municipality->department->name}",
                        ])
                        ->all()),

                TernaryFilter::make('credit_enabled')
                    ->label('Crédito')
                    ->placeholder('Todos')
                    ->trueLabel('Con crédito')
                    ->falseLabel('Contado'),

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
                'department:id,name',
                'municipality:id,name',
            ]))
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    /**
     * Segunda línea de la columna Cliente: "C-000001 · PULPERÍA LA BENDICIÓN".
     * Un cliente inactivo lo dice ahí mismo.
     */
    private static function clientDetails(EdtClient $record): string
    {
        return collect([
            $record->code,
            $record->business_name,
            $record->is_active ? null : 'INACTIVO',
        ])->filter(fn (?string $part): bool => filled($part))->implode(' · ');
    }

    /**
     * "Contado", "Crédito" (sin límite definido) o "L 5,000.00".
     */
    private static function creditLabel(EdtClient $record): string
    {
        if (! $record->credit_enabled) {
            return 'Contado';
        }

        return $record->credit_limit === null
            ? 'Crédito'
            : 'L '.number_format((float) $record->credit_limit, 2);
    }
}
