<?php

namespace App\Filament\Resources\Edt\Routes\Schemas;

use App\Casts\Uppercase;
use App\Models\Edt\EdtRoute;
use App\Models\Geo\Municipality;
use App\Models\Warehouse;
use App\Support\WarehouseScope;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Formulario de la ruta. El vendedor NO se elige aquí: se asigna con la
 * acción «Asignar vendedor» (con fecha desde), que deja historial.
 */
class EdtRouteForm
{
    /** Solo visual: el cast Uppercase del modelo es el que normaliza. */
    private const UPPERCASE_INPUT = ['style' => 'text-transform: uppercase'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ruta')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(100)
                        ->extraInputAttributes(self::UPPERCASE_INPUT)
                        ->placeholder('Ej. RUTA LUNES SANTA ROSA')
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => Uppercase::normalize($state))
                        // Único dentro de la bodega (dos bodegas pueden tener "RUTA 1").
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('warehouse_id', (int) $get('warehouse_id')),
                        ),

                    Select::make('warehouse_id')
                        ->label('Bodega')
                        ->relationship(
                            'warehouse',
                            'name',
                            modifyQueryUsing: fn (Builder $query): Builder => WarehouseScope::apply($query, 'id')->orderBy('code'),
                        )
                        ->getOptionLabelFromRecordUsing(fn (Warehouse $record): string => "{$record->code} · {$record->name}")
                        ->preload()
                        ->required()
                        ->default(fn (): ?int => self::defaultWarehouseId())
                        // Con historial de vendedores la ruta queda en su bodega.
                        ->disabled(fn (?EdtRoute $record): bool => $record?->assignments()->exists() ?? false),

                    Toggle::make('is_active')
                        ->label('Activa')
                        ->default(true)
                        ->inline(false)
                        ->helperText('Inactiva: su vendedor deja de ver estos clientes por esta ruta.'),
                ]),

            Section::make('Zona y días de visita')
                ->columnSpanFull()
                ->schema([
                    Select::make('municipalities')
                        ->label('Municipios')
                        ->relationship(
                            'municipalities',
                            'name',
                            modifyQueryUsing: fn (Builder $query): Builder => $query->with('department:id,name')->orderBy('hn_municipalities.name'),
                        )
                        // Varios municipios se llaman igual (SAN JOSÉ, CONCEPCIÓN…).
                        ->getOptionLabelFromRecordUsing(fn (Municipality $record): string => "{$record->name} · {$record->department->name}")
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText('El vendedor de la ruta ve los clientes de su bodega en estos municipios.'),

                    CheckboxList::make('visit_days')
                        ->label('Días de visita')
                        ->options(EdtRoute::WEEKDAYS)
                        ->columns(4)
                        ->gridDirection('row')
                        ->helperText('Sin marcar ninguno = cualquier día. Con días, el filtro «Solo ruta de hoy» del vendedor la muestra solo esos días.'),
                ]),
        ]);
    }

    private static function defaultWarehouseId(): ?int
    {
        $ids = WarehouseScope::apply(Warehouse::query(), 'id')->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
