<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sirve archivos de exportación almacenados en storage/app/exports/.
 *
 * Los archivos se generan en background (ShouldQueue) y se notifica
 * al usuario vía Filament database notification con un link a esta ruta.
 *
 * Seguridad:
 *  - Solo usuarios autenticados (middleware auth).
 *  - Solo permite descargar desde el directorio exports/ (previene path traversal).
 *  - Elimina el archivo después de descargarlo (descarga única).
 */
class ExportDownloadController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $filePath = $request->query('file', '');

        // Prevenir path traversal: solo permitir archivos dentro de exports/
        if (!str_starts_with($filePath, 'exports/') || str_contains($filePath, '..')) {
            abort(403, 'Ruta no permitida.');
        }

        if (!Storage::disk('local')->exists($filePath)) {
            abort(404, 'El archivo ya no está disponible. Las exportaciones se eliminan después de 24 horas.');
        }

        $fullPath = Storage::disk('local')->path($filePath);
        $fileName = basename($filePath);

        return response()->download($fullPath, $fileName)->deleteFileAfterSend(true);
    }
}
