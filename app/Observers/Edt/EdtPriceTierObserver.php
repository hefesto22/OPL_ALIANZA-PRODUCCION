<?php

namespace App\Observers\Edt;

use App\Enums\Edt\PriceChangeReason;
use App\Models\Edt\EdtPriceTier;
use App\Models\Edt\EdtProduct;
use App\Services\Edt\EdtPriceHistoryService;

/**
 * Las escalas mayoristas aplican a todo el catálogo: un cambio que mueve
 * precios escribe una fila de historial por producto, en la misma transacción
 * (SavesAtomically en EdtPriceTier).
 *
 * Solo cuando de verdad cambian precios:
 *  - crear o borrar una escala ACTIVA;
 *  - activarla o desactivarla;
 *  - cambiar cajas o porcentaje de una escala activa.
 * Renombrarla, o tocar una escala inactiva, no mueve ningún precio.
 */
class EdtPriceTierObserver
{
    public function __construct(private readonly EdtPriceHistoryService $history) {}

    public function saved(EdtPriceTier $tier): void
    {
        // wasRecentlyCreated sigue en true en la instancia después del insert;
        // si la misma instancia se vuelve a guardar, ya trae cambios.
        $justCreated = $tier->wasRecentlyCreated && $tier->getChanges() === [];

        $movesPrices = $justCreated
            ? $tier->is_active
            : $tier->wasChanged('is_active') || ($tier->is_active && $tier->wasChanged(['min_boxes', 'discount_pct']));

        if (! $movesPrices) {
            return;
        }

        $verb = $justCreated ? 'Nueva escala' : 'Cambio en escala';

        $this->history->recordForProducts(EdtProduct::query(), PriceChangeReason::Escalas, "{$verb} {$tier->name}");
    }

    public function deleted(EdtPriceTier $tier): void
    {
        if (! $tier->is_active) {
            return;
        }

        $this->history->recordForProducts(EdtProduct::query(), PriceChangeReason::Escalas, "Escala borrada {$tier->name}");
    }
}
