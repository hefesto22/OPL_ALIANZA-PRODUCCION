<?php

namespace App\Filament\Resources\Edt\Products\Schemas;

use App\Casts\Uppercase;
use App\Filament\Resources\Edt\Products\Support\EdtPriceDisplay;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtSupplier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Formulario de productos del EDT.
 *
 * Al CREAR se capturan también los datos que mueven el precio (proveedor,
 * precio de lista, unidades por caja, ISV). Al EDITAR esos campos quedan
 * bloqueados: se cambian con la acción "Cambiar precio", que pide motivo y
 * deja la fila en el historial. Un campo disabled no se envía, así que no se
 * pueden colar por el formulario de edición.
 */
class EdtProductForm
{
    /** Solo visual: el cast Uppercase del modelo es el que normaliza. */
    private const UPPERCASE_INPUT = ['style' => 'text-transform: uppercase'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Producto')
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Select::make('supplier_id')
                        ->label('Proveedor')
                        ->relationship('supplier', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        // Con un solo proveedor (hoy: EDT) ya viene elegido.
                        ->default(fn (): ?int => EdtSupplier::query()->count() === 1
                            ? EdtSupplier::query()->value('id')
                            : null)
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->helperText(fn (string $operation): ?string => $operation === 'edit'
                            ? 'El proveedor define el costo; no se cambia desde aquí.'
                            : null),

                    TextInput::make('code')
                        ->label('Código')
                        ->required()
                        ->maxLength(30)
                        ->mutateStateForValidationUsing(fn (?string $state): ?string => Uppercase::normalize($state))
                        ->unique(ignoreRecord: true)
                        ->extraInputAttributes(self::UPPERCASE_INPUT),

                    TextInput::make('description')
                        ->label('Descripción')
                        ->required()
                        ->maxLength(200)
                        ->extraInputAttributes(self::UPPERCASE_INPUT)
                        ->columnSpanFull(),

                    self::suggestedText('category', 'Categoría'),
                    self::suggestedText('family', 'Familia'),
                    self::suggestedText('presentation', 'Presentación'),

                    Toggle::make('is_active')
                        ->label('Activo')
                        ->default(true)
                        ->inline(false),
                ]),

            Section::make('Precio')
                ->columnSpanFull()
                ->description(fn (string $operation): ?string => $operation === 'edit'
                    ? 'Para cambiar el precio, las unidades por caja o el ISV usa «Cambiar precio»: queda en el historial con su motivo.'
                    : null)
                ->columns(3)
                ->schema([
                    TextInput::make('list_price')
                        ->label('Precio de lista sin ISV')
                        ->helperText('El del proveedor (columna "PVD - ISV" del Excel). Es también el precio al detalle sin ISV.')
                        ->required()
                        ->numeric()
                        ->minValue(0.0001)
                        ->step(0.0001)
                        ->maxValue(99999999)
                        // Máximo 4 decimales: es lo que guarda la BD. Sin esta regla la vista
                        // previa calcularía con más decimales que lo que se guarda.
                        ->rule('decimal:0,4')
                        ->prefix('L')
                        ->live(onBlur: true)
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),

                    TextInput::make('units_per_box')
                        ->label('Unidades por caja')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(100000)
                        ->live(onBlur: true)
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),

                    Select::make('isv_pct')
                        ->label('ISV')
                        ->options(EdtProduct::ISV_RATES)
                        ->required()
                        ->live()
                        // En BD es "15.00"; la opción es "15".
                        ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : (string) (int) $state)
                        ->disabled(fn (string $operation): bool => $operation === 'edit'),

                    TextEntry::make('price_preview')
                        ->label('Precios calculados')
                        ->state(fn (Get $get, ?EdtProduct $record, string $operation): HtmlString|string => $operation === 'edit' && $record
                            ? EdtPriceDisplay::previewTable($record->priceQuote())
                            : EdtPriceDisplay::previewTable(EdtPriceDisplay::quoteFromInput(
                                $get('supplier_id'),
                                $get('list_price'),
                                $get('units_per_box'),
                                $get('isv_pct'),
                            )))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Texto libre en mayúsculas que sugiere los valores ya usados, para que
     * no aparezcan "BIFRUTAS" y "BI FRUTAS" como dos familias.
     */
    private static function suggestedText(string $column, string $label): TextInput
    {
        return TextInput::make($column)
            ->label($label)
            ->maxLength(60)
            ->extraInputAttributes(self::UPPERCASE_INPUT)
            ->datalist(fn (): array => EdtProduct::query()
                ->whereNotNull($column)
                ->distinct()
                ->orderBy($column)
                ->pluck($column)
                ->all());
    }
}
