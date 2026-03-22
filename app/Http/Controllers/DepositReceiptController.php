<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepositReceiptController extends Controller
{
    /**
     * Sirve la imagen del comprobante de depósito de forma segura.
     *
     * La imagen está en el disco 'local' (storage/app/deposits/receipts/)
     * y nunca es accesible directamente vía URL pública.
     * El middleware 'auth' garantiza que solo usuarios autenticados
     * pueden ver los comprobantes.
     *
     * Para ampliar la restricción en el futuro (ej. solo el creador
     * o usuarios de la misma bodega), agregar un Gate o Policy aquí.
     */
    public function show(Deposit $deposit): StreamedResponse|Response
    {
        if (!$deposit->receipt_image) {
            abort(404, 'Este depósito no tiene comprobante adjunto.');
        }

        if (!Storage::disk('local')->exists($deposit->receipt_image)) {
            abort(404, 'El archivo del comprobante ya no está disponible.');
        }

        $mime = Storage::disk('local')->mimeType($deposit->receipt_image)
            ?: 'image/jpeg';

        return response()->stream(
            fn () => fpassthru(Storage::disk('local')->readStream($deposit->receipt_image)),
            200,
            [
                'Content-Type'        => $mime,
                'Content-Disposition' => 'inline; filename="comprobante-deposito-' . $deposit->id . '.jpg"',
                'Cache-Control'       => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
