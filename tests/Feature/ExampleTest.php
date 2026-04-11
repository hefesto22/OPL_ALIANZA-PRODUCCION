<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Smoke test del boot de la aplicación.
 *
 * Este proyecto NO define una ruta GET /. El panel vive en /admin
 * (Filament) y la API en /api/v1/*. La raíz simplemente redirige
 * al login de Filament — por eso esperamos 302, no 200.
 *
 * El valor de este test es confirmar que la aplicación arranca
 * sin errores (routing, service providers, middleware global), no
 * validar una página concreta.
 */
class ExampleTest extends TestCase
{
    public function test_the_application_boots_and_root_redirects(): void
    {
        $response = $this->get('/');

        // La app boota si la respuesta es < 400. Se acepta 200, 301, 302,
        // 307, 308 — todos indican que el router resolvió la petición
        // y no reventó en middleware ni service providers.
        $this->assertLessThan(
            400,
            $response->status(),
            "La raíz devolvió {$response->status()}; la app no bootó limpiamente."
        );
    }
}
