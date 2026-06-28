<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Manifest;
use App\Services\Escp\EscpInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;

/**
 * Impresión ESC/P (matriz de punto Epson LX-350) integrada al flujo web.
 *
 *   GET  /imprimir/facturas/matriz?payload=...   → vista con preview + QZ Tray
 *   GET  /imprimir/facturas/matriz/prn?payload=  → descarga .prn (respaldo)
 *
 * La vista muestra el MISMO layout monoespaciado que se imprime (WYSIWYG) y
 * un botón "Imprimir" que envía el flujo ESC/P crudo a la impresora vía
 * QZ Tray (puente local en la PC del operador). Si QZ Tray no está, el
 * operador usa el botón de descarga .prn como alternativa.
 *
 * Mismo payload cifrado y mismas protecciones de conteo que la vista HTML
 * gráfica (PrintInvoicesController).
 */
class PrintInvoicesEscpController extends Controller
{
    public function __construct(private readonly EscpInvoiceService $escp) {}

    /** Vista imprimible con preview + integración QZ Tray. */
    public function show(Request $request): Response
    {
        [$manifest, $invoices] = $this->load($request);

        $escpBytes = $this->escp->build($invoices);

        $html = view('pdf.invoice-escp', [
            'manifest' => $manifest,
            'invoices' => $invoices,
            'previewText' => $this->escp->previewText($invoices),
            'escpBase64' => base64_encode($escpBytes),
            'printerHint' => (string) config('escp.printer_name_hint', ''),
            'invoiceIds' => $invoices->pluck('id')->all(),
        ])->render();

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** Descarga .prn (flujo ESC/P crudo) como respaldo si QZ Tray no está. */
    public function download(Request $request): Response
    {
        [$manifest, $invoices] = $this->load($request);

        $bytes = $this->escp->build($invoices);
        $filename = 'facturas_manifiesto_'.$manifest->number.'_'.now()->format('Ymd_His').'.prn';

        return response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    /**
     * Descifra el payload, valida y carga las facturas (mismo filtro y
     * guards que la vista HTML gráfica).
     *
     * @return array{0: Manifest, 1: \Illuminate\Support\Collection<int, Invoice>}
     */
    private function load(Request $request): array
    {
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

        $maxInvoices = (int) config('api.print_max_invoices_per_request', 1000);
        if (! empty($invoiceIds) && count($invoiceIds) > $maxInvoices) {
            abort(422, "Demasiadas facturas en una sola impresión (máx {$maxInvoices}).");
        }

        $manifest = Manifest::findOrFail($manifestId);

        $query = $manifest->invoices()
            ->with(['lines', 'manifest'])
            ->whereNotNull('warehouse_id')
            ->where('status', '!=', 'rejected');

        if (! empty($invoiceIds)) {
            $query->whereIn('id', $invoiceIds);
        }

        if ((clone $query)->count() > $maxInvoices) {
            abort(422, "El manifiesto tiene demasiadas facturas elegibles (máx {$maxInvoices}).");
        }

        $invoices = $query->orderBy('route_number')->orderBy('invoice_number')->get();

        if ($invoices->isEmpty()) {
            abort(404, 'No hay facturas para imprimir.');
        }

        return [$manifest, $invoices];
    }
}
