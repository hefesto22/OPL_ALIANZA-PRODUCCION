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
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
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
 * resto se calcula. El ISV y las unidades por caja no se cambian aquí: son
 * datos del producto y se corrigen en Editar. Muestra los precios actuales y los nuevos lado a lado,
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
            ->modalDescription(fn (EdtProduct $record): HtmlString => self::header($record))
            ->modalSubmitActionLabel('Guardar precio')
            ->fillForm(fn (EdtProduct $record): array => [
                // Arranca con lo vigente: precio por unidad y costo por caja, con ISV.
                PriceInputFields::INCLUDES_ISV => true,
                PriceInputFields::PRICE => $record->priceQuote()->retailUnitPrice,
                PriceInputFields::COST => EdtPriceDisplay::plainAmount($record->priceQuote()->boxCost),
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
                                    // El ISV y las unidades por caja son datos del producto: los
                                    // cálculos los usan, se ven en el encabezado y se cambian en Editar.
                                    Hidden::make('isv_pct'),
                                    Hidden::make('units_per_box'),
                                ]),

                            Section::make('¿Por qué cambia?')
                                ->icon(Heroicon::OutlinedClipboardDocumentList)
                                ->description('Queda en el historial del producto.')
                                ->compact()
                                ->schema([
                                    Radio::make('reason')
                                        ->hiddenLabel()
                                        ->options(PriceChangeReason::manualOptions())
                                        ->descriptions(PriceChangeReason::manualDescriptions())
                                        ->required(),

                                    TextInput::make('note')
                                        ->label('Nota (opcional)')
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
                                                $record->priceQuote()->boxCost,
                                            ),
                                        ),
                                        before: PriceSnapshot::fromProduct($record),
                                    )),
                            ])
                            ->columnSpan(['lg' => 3]),
                    ]),
            ])
            ->action(function (EdtProduct $record, array $data): void {
                // El ISV y las unidades son los del producto, no los del
                // formulario (los campos ocultos se pueden manipular).
                $data['isv_pct'] = (string) $record->isv_pct;
                $data['units_per_box'] = (int) $record->units_per_box;

                $changed = app(EdtProductPriceService::class)->changePrice(
                    product: $record,
                    // Si el precio con ISV no cambió, la lista vigente queda igual.
                    listPrice: (string) PriceInputFields::listPriceFromState($data, (string) $record->list_price),
                    boxCost: (string) PriceInputFields::boxCostFromState($data, $record->priceQuote()->boxCost),
                    // Solo cambian precio y costo: ISV y unidades se quedan como están.
                    unitsPerBox: (int) $record->units_per_box,
                    isvPct: (string) $record->isv_pct,
                    reason: PriceChangeReason::from($data['reason']),
                    note: $data['note'] ?? null,
                );

                $changed
                    ? Notification::make()->title('Precio actualizado')->body('Quedó en el historial del producto.')->success()->send()
                    : Notification::make()->title('Sin cambios')->body('Los datos son iguales a los vigentes; no se registró nada.')->warning()->send();
            });
    }

    /**
     * Encabezado del modal: descripción, ISV, unidades por caja, proveedor
     * y el último cambio de precio (una consulta, por índice product_id +
     * created_at).
     */
    public static function header(EdtProduct $record): HtmlString
    {
        $record->loadMissing('supplier:id,name');

        $last = $record->priceHistory()
            ->latest('created_at')
            ->latest('id')
            ->first(['id', 'reason', 'created_at']);

        return new HtmlString(view('filament.edt.change-price-header', [
            'description' => $record->description,
            'isv' => EdtPriceDisplay::isv($record->isv_pct),
            'unitsPerBox' => (int) $record->units_per_box,
            'supplier' => $record->supplier?->name,
            'lastChange' => $last ? [
                'date' => $last->created_at->format('d/m/Y'),
                'reason' => $last->reason->getLabel(),
            ] : null,
        ])->render());
    }
}
