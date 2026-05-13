<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Support\WarehouseScope;
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
     * El servidor NO marca las facturas como impresas — eso lo hace el
     * endpoint confirm() invocado desde JS tras window.afterprint, para
     * que el flag refleje impresión real y no "se sirvió la vista".
     *
     * Protecciones:
     *   - throttle:print-invoices (config/api.php) — bloquea loops.
     *   - count guard: rechaza requests con > 1000 facturas (config/api.php).
     */
    public function show(Request $request): Response
    {
        // ── 1. Descifrar y validar payload ────────────────────
        try {
            $payload = Crypt::decryptString($request->query('payload', ''));
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $manifestId = (int) ($data['manifest_id'] ?? 0);
            $invoiceIds = $data['invoice_ids'] ?? [];
        } catch (\Throwable) {
            abort(403, 'Enlace de impresión inválido o expirado.');
        }

        if ($manifestId === 0) {
            abort(400, 'Manifiesto no especificado.');
        }

        // ── 2. Count guard tempranero (si vienen IDs específicos) ─
        // Si el caller pidió N ids explícitos y N > tope, abortamos
        // sin tocar BD. Genera HTML con barcodes — pesado en CPU/RAM.
        $maxInvoices = (int) config('api.print_max_invoices_per_request', 1000);
        if (! empty($invoiceIds) && count($invoiceIds) > $maxInvoices) {
            abort(422, "Demasiadas facturas en una sola impresión (máx {$maxInvoices}). Divide en batches más pequeños.");
        }

        // ── 3. Cargar datos ───────────────────────────────────
        $manifest = Manifest::findOrFail($manifestId);

        $query = $manifest->invoices()
            ->with(['lines', 'manifest'])
            ->whereNotNull('warehouse_id')
            ->where('status', '!=', 'rejected');

        if (! empty($invoiceIds)) {
            $query->whereIn('id', $invoiceIds);
        }

        // ── 4. Count guard sobre filtros aplicados ────────────
        // Cubre el caso "todas las del manifest" (invoice_ids vacío) —
        // sin esto, un manifest de 5000 facturas pasaba derecho.
        $finalCount = (clone $query)->count();
        if ($finalCount > $maxInvoices) {
            abort(422, "El manifest tiene {$finalCount} facturas elegibles; máx por impresión es {$maxInvoices}. Filtra antes de imprimir.");
        }

        $invoices = $query
            ->orderBy('route_number')
            ->orderBy('invoice_number')
            ->get();

        if ($invoices->isEmpty()) {
            abort(404, 'No hay facturas para imprimir.');
        }

        // ── 5. Generar códigos de barras ──────────────────────
        $generator = new BarcodeGeneratorPNG;
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

        $supplier = Supplier::first();

        // ── 6. Devolver HTML puro (sin marcar como impresas) ──
        // La marca ocurre vía POST /imprimir/facturas/confirmar
        // disparado por JS en el Blade tras window.afterprint.
        $html = view('pdf.invoice-pdf', [
            'invoices' => $invoices,
            'manifest' => $manifest,
            'supplier' => $supplier,
        ])->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * POST /imprimir/facturas/confirmar
     *
     * Endpoint invocado por JS en la vista imprimible cuando el navegador
     * dispara window.afterprint. Marca las facturas como físicamente
     * impresas. Si la confirmación falla (offline, JS deshabilitado,
     * impresión a PDF, navegador que no soporta afterprint), las facturas
     * quedan como no impresas — el admin puede marcarlas a mano desde
     * Filament si fue necesario.
     *
     * Aislamiento: WarehouseScope::apply filtra por warehouse_id del user
     * autenticado. Un operador OAC no puede marcar facturas de OAS aunque
     * conozca los IDs.
     */
    public function confirm(Request $request): Response
    {
        $validated = $request->validate([
            'invoice_ids' => 'required|array|max:1000',
            'invoice_ids.*' => 'integer|min:1',
        ]);

        $query = Invoice::query()->whereIn('id', $validated['invoice_ids']);
        WarehouseScope::apply($query);

        $query->update([
            'is_printed' => true,
            'printed_at' => now(),
        ]);

        return response()->noContent();
    }
}
