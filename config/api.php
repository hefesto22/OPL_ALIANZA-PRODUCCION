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

    // ── Devoluciones: tope de rango de fechas ─────────────────────────────
    //
    // devoluciones_max_dias_rango
    //   Máximo de días (inclusive) que GET v1/devoluciones/listar acepta entre
    //   los headers Fecha y FechaHasta. Un rango mayor se rechaza con 422.
    //   Protege query, caché y tamaño de respuesta ante peticiones tipo
    //   "todo el año". 31 cubre cualquier mes calendario; subir a 92 para un
    //   trimestre sin tocar código. 0 = sin tope (no recomendado en producción).
    //
    'devoluciones_max_dias_rango' => env('API_DEVOLUCIONES_MAX_DIAS_RANGO', 31),

    // ── Devoluciones: ventana de registro y modo del filtro ───────────────
    //
    // devoluciones_ventana_dias_habiles
    //   Regla operativa (definida con Mayra, 2026-07-21): las devoluciones de
    //   un manifiesto solo pueden registrarse dentro de N días HÁBILES
    //   (lunes a sábado; el domingo no cuenta) desde la llegada del
    //   manifiesto, contando el día de llegada como día 1. Al cierre (día N
    //   a las 11:59 pm hora Honduras) el paquete se publica a Jaremar y
    //   queda CONGELADO: no se puede crear, editar ni cancelar ninguna
    //   devolución de ese manifiesto — para ningún rol, sin excepciones.
    //   Ej.: llega viernes → vie(1), sáb(2), lun(3), mar(4), mié(5) →
    //   cierra miércoles 11:59 pm; el jueves Jaremar lo consulta completo.
    //
    // devoluciones_filtro_emision
    //   Punto 1 del contrato con Jaremar (correo de Isack, 2026-07-20): el
    //   header Fecha de GET v1/devoluciones/listar filtra por fecha de
    //   EMISIÓN de la factura, no por fecha de procesado.
    //     true  → contrato nuevo: filtro por emisión + solo manifiestos con
    //             ventana cerrada (paquete completo e inmutable).
    //     false → comportamiento legacy (filtro por processed_date, sin
    //             retención). Red de seguridad para revertir la transición
    //             sin deploy si Jaremar lo pidiera.
    //
    'devoluciones_ventana_dias_habiles' => (int) env('DEVOLUCIONES_VENTANA_DIAS_HABILES', 5),
    'devoluciones_filtro_emision' => filter_var(env('DEVOLUCIONES_FILTRO_EMISION', true), FILTER_VALIDATE_BOOLEAN),

];
