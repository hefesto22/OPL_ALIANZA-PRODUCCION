<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RecalculateManifestTotalsJob;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Services\ReturnService;

/**
 * Observa el ciclo de vida de InvoiceReturn para mantener la integridad
 * del sistema cuando una devolución es eliminada (soft-delete).
 *
 * Sin este observer, eliminar una devolución deja los totales del manifiesto
 * y el status de la factura corruptos hasta el próximo recálculo manual.
 */
class InvoiceReturnObserver
{
    public function __construct(protected ReturnService $returnService) {}

    /**
     * Se dispara cuando una devolución es soft-deleted.
     *
     * Responsabilidades:
     * 1. Recalcular los totales del manifiesto en background (job asíncrono).
     * 2. Actualizar el status de la factura asociada (devuelta → parcial → importada).
     * 3. Si la devolución era aprobada, invalidar el cache de Jaremar para
     *    que el endpoint /devoluciones no sirva datos obsoletos.
     */
    public function deleted(InvoiceReturn $return): void
    {
        // Recalcular totales del manifiesto de forma sincrónica + job de respaldo.
        Manifest::find($return->manifest_id)?->recalculateTotals();
        RecalculateManifestTotalsJob::dispatch($return->manifest_id);

        // Actualizar el status de la factura.
        // Cargamos la relación fresca para evitar datos en cache del modelo.
        $invoice = $return->invoice()->withTrashed()->first();

        if ($invoice) {
            $this->returnService->refreshInvoiceStatus($invoice);
        }

        // Solo las devoluciones aprobadas aparecen en la API de Jaremar.
        // Si se elimina una aprobada, la fecha de processed_date ya no
        // existe en el índice → hay que invalidar el cache de ese día.
        if ($return->isApproved() && $return->processed_date) {
            $this->returnService->invalidateDevolucionesCache(
                $return->processed_date->toDateString()
            );
        }
    }

    /**
     * Se dispara en force-delete (borrado físico).
     * Mismo comportamiento que el soft-delete.
     */
    public function forceDeleted(InvoiceReturn $return): void
    {
        $this->deleted($return);
    }
}
