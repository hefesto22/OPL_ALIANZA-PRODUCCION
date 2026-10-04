<?php

namespace App\Observers\Edt;

use App\Enums\Edt\PriceChangeReason;
use App\Models\Edt\EdtSupplier;
use App\Services\Edt\EdtPriceHistoryService;

/**
 * Cambiar el descuento de operación de un proveedor cambia el costo de todos
 * sus productos: una fila de historial por producto, en la misma transacción
 * del cambio (SavesAtomically en EdtSupplier).
 */
class EdtSupplierObserver
{
    public function __construct(private readonly EdtPriceHistoryService $history) {}

    public function updated(EdtSupplier $supplier): void
    {
        if (! $supplier->wasChanged('operation_discount_pct')) {
            return;
        }

        $this->history->recordForProducts(
            $supplier->products(),
            PriceChangeReason::DescuentoProveedor,
            'Descuento de '.$supplier->getOriginal('operation_discount_pct').'% a '.$supplier->operation_discount_pct.'%',
        );
    }
}
