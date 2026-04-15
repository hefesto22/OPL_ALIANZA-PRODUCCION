<?php

namespace App\Filament\Resources\Catalogs;

use App\Models\ReturnReason;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
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

class ReturnReasonResource extends Resource
{
    protected static ?string $model = ReturnReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'Motivo de Devolución';

    protected static ?string $pluralModelLabel = 'Motivos de Devolución';

    protected static ?int $navigationSort = 1;

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
                ->maxLength(20)
                ->unique(ignoreRecord: true)
                ->placeholder('Ej. BE-01'),

            Select::make('category')
                ->label('Categoría')
                ->required()
                ->options([
                    'BE' => 'BE — Bodega/Entrega',
                    'PD' => 'PD — Producto Dañado',
                    'PV' => 'PV — Producto Vencido',
                    'CF' => 'CF — Cliente no Firmó',
                    'CE' => 'CE — Cliente sin Efectivo',
                    'OT' => 'OT — Otros',
                ])
                ->searchable(),

            TextInput::make('description')
                ->label('Descripción')
                ->required()
                ->maxLength(150)
                ->columnSpanFull()
                ->placeholder('Ej. Cliente No Quiere (INV ALTO)'),

            TextInput::make('jaremar_id')
                ->label('ID en Jaremar')
                ->maxLength(20)
                ->placeholder('Código que usa Jaremar internamente')
                ->helperText('Opcional — solo si Jaremar asigna un código propio a este motivo.'),

            Toggle::make('is_active')
                ->label('Activo')
                ->default(true)
                ->helperText('Los motivos inactivos no aparecen al registrar devoluciones.'),
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
                    ->color('primary'),

                TextColumn::make('category')
                    ->label('Categoría')
                    ->sortable()
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'BE' => 'info',
                        'PD' => 'danger',
                        'PV' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('description')
                    ->label('Descripción')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(fn ($record) => $record->description),

                TextColumn::make('jaremar_id')
                    ->label('ID Jaremar')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                ToggleColumn::make('is_active')
                    ->label('Activo')
                    ->sortable(),

                TextColumn::make('deleted_at')
                    ->label('Eliminado')
                    ->dateTime('d/m/Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoría')
                    ->options([
                        'BE' => 'BE — Bodega/Entrega',
                        'PD' => 'PD — Producto Dañado',
                        'PV' => 'PV — Producto Vencido',
                        'CF' => 'CF — Cliente no Firmó',
                        'CE' => 'CE — Cliente sin Efectivo',
                        'OT' => 'OT — Otros',
                    ]),

                SelectFilter::make('is_active')
                    ->label('Estado')
                    ->options([
                        '1' => 'Activos',
                        '0' => 'Inactivos',
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
            'index' => \App\Filament\Resources\Catalogs\ReturnReasonResource\Pages\ListReturnReasons::route('/'),
        ];
    }
}
