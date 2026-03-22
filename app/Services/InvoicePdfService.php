<?php

namespace App\Services;

use App\Models\Manifest;
use Illuminate\Support\Facades\Crypt;

class InvoicePdfService
{
    /**
     * Genera la URL cifrada para la vista de impresión en browser.
     *
     * El controller PrintInvoicesController descifra el payload,
     * carga las facturas y devuelve HTML listo para Ctrl+P.
     *
     * @param  Manifest  $manifest
     * @param  int[]     $invoiceIds  Vacío = todas las del manifiesto
     */
    public function generatePrintUrl(Manifest $manifest, array $invoiceIds = []): string
    {
        $payload = Crypt::encryptString(json_encode([
            'manifest_id' => $manifest->id,
            'invoice_ids' => $invoiceIds,
        ]));

        return route('invoices.print', ['payload' => $payload]);
    }

    public function filename(Manifest $manifest): string
    {
        return 'facturas_manifiesto_' . $manifest->number . '_' . now()->format('Ymd_His') . '.pdf';
    }
}