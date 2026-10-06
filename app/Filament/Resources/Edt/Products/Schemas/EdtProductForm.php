<?php

namespace App\Filament\Resources\Edt\Products\Schemas;

use App\Casts\Uppercase;
use App\Filament\Resources\Edt\Products\Support\EdtPriceDisplay;
use App\Filament\Resources\Edt\Products\Support\EdtPricePreview;
use App\Filament\Resources\Edt\Products\Support\PriceInputFields;
use App\Filament\Resources\Edt\Products\Support\PriceSnapshot;
use App\Models\Edt\EdtProduct;
use App\Models\Edt\EdtSupplier;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Formulario de productos del EDT.
 *
 * Al CREAR se capturan también los datos que mueven el precio (proveedor,
 * precio de venta, costo por caja, unidades por caja, ISV). Se escriben con
 * ISV por defecto (PriceInputFields) y CreateEdtProduct los convierte a lo
 * que se guarda (lista sin ISV y costo por caja con ISV). Al EDITAR esos campos quedan
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
                    ? 'Para cambiar el precio, el costo, las unidades por caja o el ISV usa «Cambiar precio»: queda en el historial con su motivo.'
                    : null)
                ->schema([
                    Grid::make(['default' => 1, 'lg' => 5])->schema([
                        // Izquierda: lo que se escribe. Derecha: cómo queda.
                        Group::make([
                            PriceInputFields::includesIsvToggle()
                                ->visibleOn('create'),

                            PriceInputFields::priceInput()
                                ->visibleOn('create'),

                            PriceInputFields::costInput()
                                ->visibleOn('create'),

                            // En edición solo se muestra (bloqueado) lo que está guardado.
                            Grid::make(2)
                                ->visibleOn('edit')
                                ->schema([
                                    TextInput::make('list_price')
                                        ->label('Lista por unidad, sin ISV')
                                        ->prefix('L')
                                        ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : EdtPriceDisplay::plainAmount((string) $state))
                                        ->disabled(),

                                    TextInput::make('box_cost')
                                        ->label('Costo por caja, con ISV')
                                        ->prefix('L')
                                        ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : EdtPriceDisplay::plainAmount((string) $state))
                                        ->disabled(),
                                ]),

                            PriceInputFields::isvButtons()
                                // En BD es "15.00"; la opción es "15".
                                ->formatStateUsing(fn (mixed $state): ?string => $state === null ? null : (string) (int) $state)
                                ->disabled(fn (string $operation): bool => $operation === 'edit'),

                            PriceInputFields::unitsPerBoxInput()
                                ->disabled(fn (string $operation): bool => $operation === 'edit'),
                        ])->columnSpan(['lg' => 2]),

                        TextEntry::make('price_preview')
                            ->label(fn (string $operation): string => $operation === 'edit' ? 'Precios vigentes' : 'Así quedan los precios')
                            ->state(fn (Get $get, ?EdtProduct $record, string $operation): HtmlString => EdtPricePreview::render(
                                $operation === 'edit' && $record
                                    ? PriceSnapshot::fromProduct($record)
                                    : PriceSnapshot::fromInput(
                                        PriceInputFields::listPriceFromInput(
                                            $get(PriceInputFields::PRICE),
                                            $get(PriceInputFields::INCLUDES_ISV),
                                            $get('isv_pct'),
                                        ),
                                        $get('units_per_box'),
                                        $get('isv_pct'),
                                        PriceInputFields::boxCostFromInput(
                                            $get(PriceInputFields::COST),
                                            $get(PriceInputFields::INCLUDES_ISV),
                                            $get('isv_pct'),
                                        ),
                                    ),
                            ))
                            ->columnSpan(['lg' => 3]),
                    ]),
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
