<?php

namespace App\Filament\Resources\Edt\PriceTiers;

use App\Casts\Uppercase;
use App\Filament\Resources\Edt\PriceTiers\Pages\ManageEdtPriceTiers;
use App\Models\Edt\EdtPriceTier;
use App\Models\Edt\EdtProduct;
use App\Support\Edt\EdtModule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Escalas mayoristas del EDT (configurables). Pantalla simple: crear y
 * editar en ventana modal.
 *
 * Cualquier cambio de cajas, porcentaje o estado mueve los precios de TODO
 * el catálogo; EdtPriceTierObserver escribe una fila de historial por
 * producto. Por eso el formulario lo advierte antes de guardar.
 */
class EdtPriceTierResource extends Resource
{
    protected static ?string $model = EdtPriceTier::class;

    protected static ?string $slug = 'edt/escalas-mayoristas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Escala mayorista';

    protected static ?string $pluralModelLabel = 'Escalas mayoristas';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return EdtModule::NAVIGATION_GROUP;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(50)
                    ->mutateStateForValidationUsing(fn (?string $state): ?string => Uppercase::normalize($state))
                    ->unique(ignoreRecord: true)
                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                    ->placeholder('Ej. MAYORISTA 3'),

                TextInput::make('min_boxes')
                    ->label('Desde (cajas del mismo código)')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->unique(ignoreRecord: true),

                TextInput::make('discount_pct')
                    ->label('Descuento sobre precio de lista')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(99.99)
                    ->step(0.01)
                    ->suffix('%'),

                Toggle::make('is_active')
                    ->label('Activa')
                    ->default(true)
                    ->inline(false),

                TextEntry::make('impact_notice')
                    ->hiddenLabel()
                    ->state(fn (): string => 'Al guardar se recalculan los precios mayoristas de '
                        .EdtProduct::query()->count().' productos y queda en el historial de cada uno.')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->weight('bold'),

                TextColumn::make('min_boxes')
                    ->label('Desde (cajas del mismo código)')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('discount_pct')
                    ->label('Descuento')
                    ->suffix('%')
                    ->alignEnd(),

                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label('Actualizada')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('min_boxes')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Los precios mayoristas de todo el catálogo se recalculan sin esta escala y queda en el historial de cada producto.'),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEdtPriceTiers::route('/'),
        ];
    }
}
