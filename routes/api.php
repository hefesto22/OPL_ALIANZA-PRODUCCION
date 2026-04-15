<?php

use App\Http\Controllers\Api\V1\DevolucionesController;
use App\Http\Controllers\Api\V1\ManifestApiController;
use App\Http\Middleware\ValidateApiKey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Sistema Distribuidora Hosana
|--------------------------------------------------------------------------
|
| Versión: v1
| Autenticación: Header ApiKey (middleware ValidateApiKey)
|
| Rate limits (configurables en config/api.php sin tocar código):
|   - General (api):         rate_limit_per_minute          → todos los endpoints
|   - Insertar (insertar):   rate_limit_insertar_per_minute → POST facturas/insertar
|   - Devoluciones:          rate_limit_devoluciones_per_minute → GET devoluciones/listar
|
*/

// ── Health check (sin autenticación) ─────────────────────────────────────
Route::get('v1/ping', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'Distribuidora Hosana API',
        'version' => 'v1',
        'timestamp' => now()->toIso8601String(),
    ]);
})->name('api.v1.ping');

Route::prefix('v1')
    ->middleware(['throttle:api', ValidateApiKey::class])
    ->group(function () {

        // ── Facturas ──────────────────────────────────────────────────────
        // Throttle propio: es el endpoint más pesado del sistema.
        // Procesa miles de facturas por llamada — limitado a 5/min por IP
        // para prevenir saturación ante errores en el sistema de Jaremar.
        Route::post('facturas/insertar', [ManifestApiController::class, 'insertar'])
            ->middleware('throttle:insertar')
            ->name('api.v1.facturas.insertar');

        // ── Manifiestos ───────────────────────────────────────────────────
        Route::get('manifiestos/{numero}/estado', [ManifestApiController::class, 'estado'])
            ->name('api.v1.manifiestos.estado')
            ->where('numero', '[0-9]+');

        // ── Devoluciones ──────────────────────────────────────────────────
        // Throttle propio: permite ajustar el límite de este endpoint
        // de forma independiente sin afectar los demás.
        Route::get('devoluciones/listar', [DevolucionesController::class, 'listar'])
            ->middleware('throttle:devoluciones')
            ->name('api.v1.devoluciones.listar');
    });
