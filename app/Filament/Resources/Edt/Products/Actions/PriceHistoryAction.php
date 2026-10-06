<?php

namespace App\Filament\Resources\Edt\Products\Actions;

use App\Models\Edt\EdtProduct;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * "Historial de precios" desde el listado, sin entrar a editar el producto.
 * Muestra la misma tabla que la pantalla de edición
 * (PriceHistoryRelationManager), así hay una sola definición de columnas.
 *
 * Quien puede ver el producto ve su historial; al vendedor de ruta la
 * tabla le oculta costo y lista, igual que el listado.
 */
class PriceHistoryAction
{
    public static function make(): Action
    {
        return Action::make('priceHistory')
            ->label('Historial de precios')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->authorize('view')
            ->modalHeading(fn (EdtProduct $record): string => "Historial de precios · {$record->code}")
            ->modalDescription(fn (EdtProduct $record): string => $record->description)
            ->modalWidth(Width::SevenExtraLarge)
            ->modalContent(fn (EdtProduct $record): View => view('filament.edt.price-history-modal', ['record' => $record]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar');
    }
}
