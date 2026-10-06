<?php

namespace App\Filament\Resources\Edt\Products\Actions;

use App\Enums\Edt\PriceChangeReason;
use App\Filament\Resources\Edt\Products\Support\EdtPriceDisplay;
use App\Filament\Resources\Edt\Products\Support\EdtPricePreview;
use App\Filament\Resources\Edt\Products\Support\PriceInputFields;
use App\Filament\Resources\Edt\Products\Support\PriceSnapshot;
use App\Models\Edt\EdtProduct;
use App\Services\Edt\EdtProductPriceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * "Cambiar precio": se escribe el precio de venta y el costo por caja; el
 * resto se calcula. Muestra los precios actuales y los nuevos lado a lado,
 * pide motivo y deja la fila en el historial (EdtProductPriceService →
 * observer).
 *
 * Permiso propio (ChangePrice:EdtProduct): editar la descripción no es lo
 * mismo que mover precios.
 */
class ChangePriceAction
{
    public static function make(): Action
    {
        return Action::make('changePrice')
            ->label('Cambiar precio')
            ->icon(Heroicon::OutlinedCurrencyDollar)
            ->color('warning')
            ->authorize('changePrice')
            ->modalHeading(fn (EdtProduct $record): string => "Cambiar precio · {$record->code}")
            ->modalDescription(fn (EdtProduct $record): string => $record->description)
            ->modalSubmitActionLabel('Guardar precio')
            ->fillForm(fn (EdtProduct $record): array => [
                // Arranca con lo vigente: precio por unidad y costo por caja, con ISV.
                PriceInputFields::INCLUDES_ISV => true,
                PriceInputFields::PRICE => $record->priceQuote()->retailUnitPrice,
                PriceInputFields::COST => EdtPriceDisplay::plainAmount((string) $record->box_cost),
                'units_per_box' => $record->units_per_box,
                'isv_pct' => (string) (int) $record->isv_pct,
                'reason' => PriceChangeReason::ListaProveedor->value,
            ])
            ->modalWidth(Width::FiveExtraLarge)
            ->schema([
                Grid::make(['default' => 1, 'lg' => 5])
                    ->schema([
                        // Izquierda: lo que se escribe. Derecha: cómo queda.
                        Group::make([
                            Section::make('Precio y costo nuevos')
                                ->icon(Heroicon::OutlinedPencilSquare)
                                ->compact()
                                ->schema([
                                    PriceInputFields::includesIsvToggle(),
                                    PriceInputFields::priceInput(),
                                    PriceInputFields::costInput(),
                                    PriceInputFields::isvButtons(),
                                    PriceInputFields::unitsPerBoxInput(),
                                ]),

                            Section::make('Motivo del cambio')
                                ->icon(Heroicon::OutlinedClipboardDocumentList)
                                ->description('Queda en el historial del producto.')
                                ->compact()
                                ->schema([
                                    Select::make('reason')
                                        ->label('Motivo')
                                        ->options(PriceChangeReason::manualOptions())
                                        ->required(),

                                    TextInput::make('note')
                                        ->label('Nota')
                                        ->placeholder('Ej. factura 001-001-01-00012345')
                                        ->maxLength(255)
                                        ->extraInputAttributes(['style' => 'text-transform: uppercase']),
                                ]),
                        ])->columnSpan(['lg' => 2]),

                        Section::make('Así quedan los precios')
                            ->icon(Heroicon::OutlinedCalculator)
                            ->description('Se recalcula mientras escribes.')
                            ->compact()
                            ->schema([
                                TextEntry::make('price_preview')
                                    ->hiddenLabel()
                                    ->state(fn (Get $get, EdtProduct $record): HtmlString => EdtPricePreview::render(
                                        PriceSnapshot::fromInput(
                                            PriceInputFields::listPriceFromInput(
                                                $get(PriceInputFields::PRICE),
                                                $get(PriceInputFields::INCLUDES_ISV),
                                                $get('isv_pct'),
                                                (string) $record->list_price,
                                            ),
                                            $get('units_per_box'),
                                            $get('isv_pct'),
                                            PriceInputFields::boxCostFromInput(
                                                $get(PriceInputFields::COST),
                                                $get(PriceInputFields::INCLUDES_ISV),
                                                $get('isv_pct'),
                                                (string) $record->box_cost,
                                            ),
                                        ),
                                        before: PriceSnapshot::fromProduct($record),
                                    )),
                            ])
                            ->columnSpan(['lg' => 3]),
                    ]),
            ])
            ->action(function (EdtProduct $record, array $data): void {
                $changed = app(EdtProductPriceService::class)->changePrice(
                    product: $record,
                    // Si el precio con ISV no cambió, la lista vigente queda igual.
                    listPrice: (string) PriceInputFields::listPriceFromState($data, (string) $record->list_price),
                    boxCost: (string) PriceInputFields::boxCostFromState($data, (string) $record->box_cost),
                    unitsPerBox: (int) $data['units_per_box'],
                    isvPct: (string) $data['isv_pct'],
                    reason: PriceChangeReason::from($data['reason']),
                    note: $data['note'] ?? null,
                );

                $changed
                    ? Notification::make()->title('Precio actualizado')->body('Quedó en el historial del producto.')->success()->send()
                    : Notification::make()->title('Sin cambios')->body('Los datos son iguales a los vigentes; no se registró nada.')->warning()->send();
            });
    }
}
