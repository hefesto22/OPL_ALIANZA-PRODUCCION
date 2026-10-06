<?php

namespace App\Filament\Resources\Edt\Products\Pages;

use App\Filament\Resources\Edt\Products\Actions\ChangePriceAction;
use App\Filament\Resources\Edt\Products\EdtProductResource;
use App\Models\Edt\EdtProduct;
use App\Services\Edt\EdtPricingService;
use Filament\Resources\Pages\EditRecord;

/**
 * Edición de los datos del producto. El precio y el costo están bloqueados:
 * se cambian con «Cambiar precio» (acción del encabezado). El ISV y las
 * unidades por caja SÍ se corrigen aquí: el precio y el costo por unidad sin
 * ISV se mantienen y el costo por caja con ISV se recalcula. El observer deja
 * la fila EDICIÓN en el historial (PriceHistoryRelationManager, debajo).
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
                    $this->refreshFormData(['list_price', 'box_cost', 'units_per_box', 'isv_pct']);
                    $this->dispatch('refresh-price-history');
                }),
        ];
    }

    /**
     * Si cambiaron el ISV o las unidades por caja, el costo por caja con ISV
     * se recalcula (el costo por unidad sin ISV se mantiene).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var EdtProduct $product */
        $product = $this->getRecord();

        $data['box_cost'] = self::rescaledBoxCost($product, $data['units_per_box'] ?? null, $data['isv_pct'] ?? null);

        return $data;
    }

    /**
     * Costo por caja con ISV para el ISV y las unidades por caja dados. Igual
     * al guardado si no cambian.
     */
    public static function rescaledBoxCost(EdtProduct $product, mixed $unitsPerBox, mixed $isvPct): string
    {
        $current = $product->priceQuote()->boxCost;

        if (! is_numeric($unitsPerBox) || (int) $unitsPerBox < 1 || ! is_numeric($isvPct)) {
            return $current;
        }

        if ((int) $unitsPerBox === (int) $product->units_per_box && (float) $isvPct === (float) $product->isv_pct) {
            return $current;
        }

        return app(EdtPricingService::class)->rescaleBoxCost(
            $current,
            (int) $product->units_per_box,
            (string) $product->isv_pct,
            (int) $unitsPerBox,
            (string) $isvPct,
        );
    }
}
