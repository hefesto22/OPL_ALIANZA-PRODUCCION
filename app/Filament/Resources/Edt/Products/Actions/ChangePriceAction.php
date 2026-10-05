<?php

namespace App\Filament\Resources\Edt\Products\Actions;

use App\Enums\Edt\PriceChangeReason;
use App\Filament\Resources\Edt\Products\Support\EdtPriceDisplay;
use App\Filament\Resources\Edt\Products\Support\PriceInputFields;
use App\Models\Edt\EdtProduct;
use App\Services\Edt\EdtProductPriceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * "Cambiar precio": reemplaza la hoja "Calculo precios" del Excel. Muestra
 * los precios actuales y los nuevos lado a lado, pide motivo y deja la fila
 * en el historial (EdtProductPriceService → observer).
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
                // Arranca con el precio con ISV vigente (el detalle por unidad).
                PriceInputFields::INCLUDES_ISV => true,
                PriceInputFields::PRICE => $record->priceQuote()->retailUnitPrice,
                'units_per_box' => $record->units_per_box,
                'isv_pct' => (string) (int) $record->isv_pct,
                'reason' => PriceChangeReason::ListaProveedor->value,
            ])
            ->schema([
                PriceInputFields::includesIsvToggle(),

                PriceInputFields::priceInput(),

                TextInput::make('units_per_box')
                    ->label('Unidades por caja')
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(100000)
                    ->live(onBlur: true),

                Select::make('isv_pct')
                    ->label('ISV')
                    ->options(EdtProduct::ISV_RATES)
                    ->required()
                    ->live(),

                Select::make('reason')
                    ->label('Motivo')
                    ->options(PriceChangeReason::manualOptions())
                    ->required(),

                TextInput::make('note')
                    ->label('Nota')
                    ->placeholder('Ej. factura 001-001-01-00012345')
                    ->maxLength(255)
                    ->extraInputAttributes(['style' => 'text-transform: uppercase']),

                TextEntry::make('price_preview')
                    ->label('Antes y después')
                    ->state(fn (Get $get, EdtProduct $record): HtmlString|string => EdtPriceDisplay::previewTable(
                        EdtPriceDisplay::quoteFromInput(
                            $record->supplier_id,
                            PriceInputFields::listPriceFromInput(
                                $get(PriceInputFields::PRICE),
                                $get(PriceInputFields::INCLUDES_ISV),
                                $get('isv_pct'),
                                (string) $record->list_price,
                            ),
                            $get('units_per_box'),
                            $get('isv_pct'),
                        ),
                        before: $record->priceQuote(),
                    )),
            ])
            ->action(function (EdtProduct $record, array $data): void {
                $changed = app(EdtProductPriceService::class)->changePrice(
                    product: $record,
                    // Si el precio con ISV no cambió, la lista vigente queda igual.
                    listPrice: (string) PriceInputFields::listPriceFromState($data, (string) $record->list_price),
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
