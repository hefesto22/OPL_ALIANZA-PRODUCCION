<?php

namespace App\Filament\Resources\Catalogs;

use App\Models\User;
use App\Models\Warehouse;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use BackedEnum;

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $modelLabel = 'Bodega';

    protected static ?string $pluralModelLabel = 'Bodegas';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Configuración';
    }

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->hasAnyRole(['super_admin', 'admin']) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Código')
                ->required()
                ->maxLength(10)
                ->unique(ignoreRecord: true)
                ->placeholder('Ej. OAC')
                ->helperText('Debe coincidir exactamente con el código que envía Jaremar en el campo Almacen del JSON.'),

            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(100)
                ->placeholder('Ej. Bodega Choloma'),

            TextInput::make('city')
                ->label('Ciudad')
                ->maxLength(60)
                ->placeholder('Ej. Choloma'),

            TextInput::make('department')
                ->label('Departamento')
                ->maxLength(60)
                ->placeholder('Ej. Cortés'),

            TextInput::make('address')
                ->label('Dirección')
                ->maxLength(200)
                ->columnSpanFull()
                ->placeholder('Dirección física de la bodega'),

            TextInput::make('phone')
                ->label('Teléfono')
                ->maxLength(20)
                ->placeholder('Ej. 2234-5678'),

            Toggle::make('is_active')
                ->label('Activa')
                ->default(true)
                ->helperText('Las bodegas inactivas no reciben facturas nuevas.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'OAC'   => 'info',
                        'OAO'   => 'success',
                        'OAS'   => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('city')
                    ->label('Ciudad')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('department')
                    ->label('Departamento')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('phone')
                    ->label('Teléfono')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                ToggleColumn::make('is_active')
                    ->label('Activa')
                    ->sortable(),

                TextColumn::make('deleted_at')
                    ->label('Eliminada')
                    ->dateTime('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('is_active')
                    ->label('Estado')
                    ->options([
                        '1' => 'Activas',
                        '0' => 'Inactivas',
                    ]),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                RestoreAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make(),
            ])
            ->defaultSort('code', 'asc')
            ->striped();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\Catalogs\WarehouseResource\Pages\ListWarehouses::route('/'),
        ];
    }
}