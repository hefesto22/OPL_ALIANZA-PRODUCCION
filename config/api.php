<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configuración de la API — Sistema Distribuidora Hosana
    |--------------------------------------------------------------------------
    |
    | Todos los valores se pueden sobrescribir desde el .env sin tocar código.
    | Esto permite ajustar límites en producción sin hacer deploy.
    |
    */

    // ── Autenticación ─────────────────────────────────────────────────────
    // Las API keys se almacenan hasheadas en la tabla suppliers.
    // Este campo es solo una referencia de compatibilidad legacy.
    'jaremar_api_key' => env('JAREMAR_API_KEY', ''),

    // ── Rate Limits ───────────────────────────────────────────────────────
    //
    // rate_limit_per_minute
    //   Límite general para todos los endpoints API.
    //   Aplica a cualquier endpoint que no tenga throttle propio.
    //   Jaremar hace máximo 3 llamadas distintas por día en condiciones
    //   normales, 60/min es más que suficiente con margen para reintentos.
    //
    // rate_limit_insertar_per_minute
    //   Límite dedicado para POST v1/facturas/insertar.
    //   Es el endpoint más pesado — procesa miles de facturas por llamada.
    //   En condiciones normales Jaremar manda 5-10 batches por día.
    //   Si supera 5/min algo está mal en su sistema y hay que frenar.
    //
    // rate_limit_devoluciones_per_minute
    //   Límite dedicado para GET v1/devoluciones/listar.
    //   Jaremar puede llegar a 40 llamadas en cierre de mes.
    //   10/min permite ráfagas sin saturar el servidor.
    //
    // rate_limit_print_per_minute
    //   Límite dedicado para GET /imprimir/facturas (vista imprimible).
    //   Por usuario (no por IP) — los operadores de bodega imprimen
    //   ~10–20 batches en una jornada de cierre. 30/min cubre ráfagas
    //   legítimas y bloquea loops accidentales (doble-click, refresh
    //   compulsivo) que generarían HTML pesado con barcodes en CPU.
    //
    'rate_limit_per_minute' => env('API_RATE_LIMIT', 60),
    'rate_limit_insertar_per_minute' => env('API_RATE_LIMIT_INSERTAR', 5),
    'rate_limit_devoluciones_per_minute' => env('API_RATE_LIMIT_DEVOLUCIONES', 10),
    'rate_limit_print_per_minute' => env('API_RATE_LIMIT_PRINT', 30),

    // ── Print invoices: count guard ───────────────────────────────────────
    //
    // print_max_invoices_per_request
    //   Tope duro de facturas que el endpoint /imprimir/facturas puede
    //   procesar en una sola request. Genera HTML con barcodes PNG en
    //   base64 — pesado en memoria y CPU. 1000 cubre los manifests más
    //   grandes esperados; arriba de eso es señal de que el operador
    //   debe dividir el batch.
    //
    'print_max_invoices_per_request' => env('PRINT_MAX_INVOICES', 1000),

];
