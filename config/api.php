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
    'rate_limit_per_minute'              => env('API_RATE_LIMIT', 60),
    'rate_limit_insertar_per_minute'     => env('API_RATE_LIMIT_INSERTAR', 5),
    'rate_limit_devoluciones_per_minute' => env('API_RATE_LIMIT_DEVOLUCIONES', 10),

];