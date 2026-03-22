<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Picqer\Barcode\BarcodeGeneratorPNG;

class PrintInvoicesController extends Controller
{
    /**
     * GET /imprimir/facturas?payload={encrypted}
     *
     * Recibe un payload cifrado con:
     *   - manifest_id: int
     *   - invoice_ids: int[]  (vacío = todas las del manifiesto)
     *
     * Devuelve HTML listo para imprimir desde el browser.
     * El browser del usuario renderiza todo — el servidor solo
     * ejecuta la query y devuelve HTML puro. Sin PDF, sin disco.
     */
    public function show(Request $request): Response
    {
        // ── 1. Descifrar y validar payload ────────────────────
        try {
            $payload     = Crypt::decryptString($request->query('payload', ''));
            $data        = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $manifestId  = (int) ($data['manifest_id'] ?? 0);
            $invoiceIds  = $data['invoice_ids'] ?? [];
        } catch (\Throwable) {
            abort(403, 'Enlace de impresión inválido o expirado.');
        }

        if ($manifestId === 0) {
            abort(400, 'Manifiesto no especificado.');
        }

        // ── 2. Cargar datos ───────────────────────────────────
        $manifest = Manifest::findOrFail($manifestId);

        $query = $manifest->invoices()
            ->with(['lines', 'manifest'])
            ->whereNotNull('warehouse_id')
            ->where('status', '!=', 'rejected');

        if (!empty($invoiceIds)) {
            $query->whereIn('id', $invoiceIds);
        }

        $invoices = $query
            ->orderBy('route_number')
            ->orderBy('invoice_number')
            ->get();

        if ($invoices->isEmpty()) {
            abort(404, 'No hay facturas para imprimir.');
        }

        // ── 3. Generar códigos de barras ──────────────────────
        $generator = new BarcodeGeneratorPNG();
        $invoices->each(function (Invoice $invoice) use ($generator): void {
            $invoice->barcode_base64 = base64_encode(
                $generator->getBarcode(
                    $invoice->invoice_number,
                    BarcodeGeneratorPNG::TYPE_CODE_128_B,
                    1,
                    28
                )
            );
        });

        // ── 4. Marcar como impresas ───────────────────────────
        Invoice::whereIn('id', $invoices->pluck('id'))
            ->update(['is_printed' => true, 'printed_at' => now()]);

        $supplier = Supplier::first();

        // ── 5. Devolver HTML puro ─────────────────────────────
        $html = view('pdf.invoice-pdf', [
            'invoices' => $invoices,
            'manifest' => $manifest,
            'supplier' => $supplier,
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}