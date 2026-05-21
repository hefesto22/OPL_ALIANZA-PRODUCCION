<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configuración de Manifiestos — Sistema Distribuidora Hosana
    |--------------------------------------------------------------------------
    |
    | Reglas de validación de fechas para los manifiestos importados desde
    | Jaremar vía POST /api/v1/facturas/insertar.
    |
    | Todos los umbrales se pueden ajustar desde el .env sin tocar código.
    | El TZ está fijo (Honduras no aplica DST y la operación es 100% local).
    |
    */

    'dates' => [

        // ── Timezone ────────────────────────────────────────────────────
        //
        // Honduras es UTC-6, sin horario de verano. Todas las comparaciones
        // de FechaFactura se normalizan a este timezone ANTES de comparar
        // contra "hoy" para evitar el bug donde una factura con timestamp
        // "2026-03-22T00:00:00.000Z" (UTC) se interpretaría incorrectamente
        // como del día siguiente o anterior en zonas con offset distinto.
        //
        'timezone' => env('MANIFESTS_TIMEZONE', 'America/Tegucigalpa'),

        // ── allow_future ────────────────────────────────────────────────
        //
        // Una FechaFactura posterior a "hoy" (en TZ Honduras) es siempre un
        // error operativo: facturación al futuro no existe legítimamente.
        // Dejarlo en false bloquea data corrupta antes de que entre al
        // sistema. No tiene contraparte legítima en operación normal.
        //
        'allow_future' => filter_var(env('MANIFESTS_ALLOW_FUTURE', false), FILTER_VALIDATE_BOOLEAN),

        // ── max_backdate_days ───────────────────────────────────────────
        //
        // Máximo número de días HACIA ATRÁS que se acepta una FechaFactura
        // respecto a "hoy". Cubre olvidos operativos razonables de Jaremar:
        // vacaciones, problemas de conectividad, lotes atrasados.
        //
        // 30 días coincide con el ciclo de declaración mensual de ISV en
        // Honduras — facturas más viejas que un mes ya están en periodos
        // fiscales probablemente declarados al SAR, y aceptarlas
        // automáticamente sería riesgo regulatorio.
        //
        // Por encima de este umbral, el batch se rechaza con HTTP 422 y
        // requiere intervención manual de admin (carga vía panel
        // administrativo con motivo registrado).
        //
        'max_backdate_days' => (int) env('MANIFESTS_MAX_BACKDATE_DAYS', 30),

        // ── reject_mixed_dates ──────────────────────────────────────────
        //
        // Regla de negocio operacional: un manifiesto representa el trabajo
        // de un único día de despacho. Si un mismo NumeroManifiesto trae
        // facturas con FechaFactura de días distintos, eso indica un error
        // en origen (Jaremar consolidó dos lotes en uno).
        //
        // Cuando está activo (recomendado), el batch se rechaza completo y
        // Jaremar debe separar el manifiesto en lotes por fecha.
        //
        'reject_mixed_dates' => filter_var(env('MANIFESTS_REJECT_MIXED_DATES', true), FILTER_VALIDATE_BOOLEAN),

    ],

];
