<?php

namespace App\Observers\Edt;

use App\Enums\Edt\PriceChangeReason;
use App\Models\Edt\EdtProduct;
use App\Services\Edt\EdtPriceHistoryService;

/**
 * Historial de precios al crear un producto o cambiarle un dato que mueve el
 * precio (EdtProduct::PRICE_FIELDS). Corre dentro de la transacción del save
 * (SavesAtomically): si el historial falla, el cambio se revierte.
 */
class EdtProductObserver
{
    public function __construct(private readonly EdtPriceHistoryService $history) {}

    public function created(EdtProduct $product): void
    {
        [$reason, $note] = $product->pullPriceChangeReason();

        $this->history->record($product, $reason ?? PriceChangeReason::Creacion, $note);
    }

    public function updated(EdtProduct $product): void
    {
        [$reason, $note] = $product->pullPriceChangeReason();

        if (! $product->wasChanged(EdtProduct::PRICE_FIELDS)) {
            return;
        }

        $this->history->record($product, $reason ?? PriceChangeReason::Edicion, $note);
    }
}
