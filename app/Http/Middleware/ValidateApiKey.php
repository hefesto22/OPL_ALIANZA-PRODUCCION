<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ValidateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('ApiKey');

        if (empty($apiKey)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'ApiKey requerido. Incluya el header ApiKey en su solicitud.',
            ], 401);
        }

        $validKey = config('api.jaremar_api_key');

        if (empty($validKey)) {
            // El ApiKey no está configurado en el servidor — error de configuración
            return new JsonResponse([
                'success' => false,
                'message' => 'El servidor no está configurado para recibir solicitudes API.',
            ], 503);
        }

        if (!hash_equals($validKey, $apiKey)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'ApiKey inválido.',
            ], 401);
        }

        return $next($request);
    }
}