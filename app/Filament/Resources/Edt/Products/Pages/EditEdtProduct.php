<?php

namespace App\Filament\Resources\Edt\Products\Pages;

use App\Filament\Resources\Edt\Products\Actions\ChangePriceAction;
use App\Filament\Resources\Edt\Products\EdtProductResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Edición de datos descriptivos. Los que mueven el precio están bloqueados
 * en el formulario y se cambian con «Cambiar precio» (acción del encabezado).
 * Debajo va el historial de precios (PriceHistoryRelationManager).
 *
 * Sin borrar: un producto con historial se desactiva.
 */
class EditEdtProduct extends EditRecord
{
    protected static string $resource = EdtProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ChangePriceAction::make()
                // Tras cambiar el precio, refrescar el formulario y la tabla
                // del historial que están en esta misma página.
                ->after(function (): void {
                    $this->refreshFormData(['list_price', 'units_per_box', 'isv_pct']);
                    $this->dispatch('refresh-price-history');
                }),
        ];
    }
}
