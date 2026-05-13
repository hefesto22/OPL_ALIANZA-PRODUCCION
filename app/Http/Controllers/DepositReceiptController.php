<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DepositReceiptController extends Controller
{
    /**
     * Sirve la imagen del comprobante de depósito con triple capa de seguridad:
     *
     *  1. Middleware `signed` — el link debe haber sido emitido por nosotros
     *     y no estar expirado (TTL 30min, ver Deposit::receipt_url accessor).
     *  2. Middleware `auth` — el usuario debe tener sesión activa.
     *  3. Policy `DepositPolicy::view` — el usuario debe pertenecer a la
     *     bodega del manifest que contiene el depósito (aislamiento por
     *     bodega vía userOwnsRecordViaRelation('manifest')).
     *
     * La imagen está en el disco 'local' (storage/app/deposits/receipts/)
     * y nunca es accesible directamente vía URL pública.
     */
    public function show(Deposit $deposit): StreamedResponse|Response
    {
        // Gate::authorize lanza AuthorizationException si la Policy retorna
        // false; Laravel la mapea a 403 automáticamente. Se usa la facade
        // Gate en vez de $this->authorize porque Controller.php base no
        // trae el trait AuthorizesRequests en Laravel 11+.
        Gate::authorize('view', $deposit);

        if (! $deposit->receipt_image) {
            abort(404, 'Este depósito no tiene comprobante adjunto.');
        }

        if (! Storage::disk('local')->exists($deposit->receipt_image)) {
            abort(404, 'El archivo del comprobante ya no está disponible.');
        }

        $mime = Storage::disk('local')->mimeType($deposit->receipt_image)
            ?: 'image/jpeg';

        return response()->stream(
            fn () => fpassthru(Storage::disk('local')->readStream($deposit->receipt_image)),
            200,
            [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline; filename="comprobante-deposito-'.$deposit->id.'.jpg"',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
