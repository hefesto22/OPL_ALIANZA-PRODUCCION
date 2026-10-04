<?php

namespace App\Services\Edt;

use App\Enums\Edt\PriceChangeReason;
use App\Models\Edt\EdtProduct;
use Illuminate\Support\Facades\DB;

/**
 * Cambio de precio de un producto del EDT (la acción "Cambiar precio", que
 * reemplaza la hoja "Calculo precios" del Excel del cliente).
 *
 * Bloquea la fila del producto mientras cambia: dos personas cambiando el
 * mismo precio a la vez quedan en orden, cada una con su fila de historial.
 */
class EdtProductPriceService
{
    /**
     * @return bool false si los datos son iguales a los vigentes (no se
     *              escribe nada ni se ensucia el historial)
     */
    public function changePrice(
        EdtProduct $product,
        string $listPrice,
        int $unitsPerBox,
        string $isvPct,
        PriceChangeReason $reason,
        ?string $note = null,
    ): bool {
        return DB::transaction(function () use ($product, $listPrice, $unitsPerBox, $isvPct, $reason, $note): bool {
            /** @var EdtProduct $locked */
            $locked = EdtProduct::query()->lockForUpdate()->findOrFail($product->id);

            $locked->fill([
                'list_price' => $listPrice,
                'units_per_box' => $unitsPerBox,
                'isv_pct' => $isvPct,
            ]);

            if (! $locked->isDirty(EdtProduct::PRICE_FIELDS)) {
                return false;
            }

            // El observer escribe el historial con este motivo, en la misma
            // transacción.
            $locked->withPriceChangeReason($reason, $note)->save();

            // La instancia de quien llamó (la fila de la tabla) queda al día.
            $product->setRawAttributes($locked->getAttributes(), sync: true);

            return true;
        });
    }
}
