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

    /*
    |--------------------------------------------------------------------------
    | Ajustes de diferencia (centavos)
    |--------------------------------------------------------------------------
    |
    | Un depósito bancario rara vez cuadra al centavo con el total a depositar.
    | Como el cierre de manifiesto exige diferencia CERO exacta, esos centavos
    | dejan manifiestos varados de por vida (al 28/07/2026 había manifiestos en
    | producción con -0.01 imposibles de cerrar).
    |
    | La solución NO es una tolerancia automática de cierre — eso no deja
    | constancia de quién autorizó dar por buenos esos centavos. Es un ajuste
    | explícito, con permiso (Adjust:Manifest), motivo obligatorio y registro
    | en el canal `finance`. Ver App\Services\ManifestAdjustmentService.
    |
    */

    'ajustes' => [

        // ── tope_hnl ────────────────────────────────────────────────────
        //
        // Monto absoluto máximo de UN ajuste individual. La regla de negocio
        // es "centavos de redondeo bancario", no "cuadrar manifiestos a mano":
        // si la diferencia supera este tope, hay un depósito o una devolución
        // sin registrar y eso se investiga, no se ajusta.
        //
        // Se puede subir desde .env sin migración si la operación lo justifica.
        //
        'tope_hnl' => (float) env('MANIFESTS_AJUSTE_TOPE_HNL', 1.00),
    ],

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
        // Si está ACTIVO, un manifiesto debe contener facturas de una sola
        // FechaFactura; mezclar dos días en un mismo NumeroManifiesto se
        // rechaza con FECHAS_MEZCLADAS.
        //
        // DEFAULT: false. Por requerimiento de Jaremar, un manifiesto puede
        // traer facturas de varias fechas (lote de carga, no día operativo
        // único). Cada factura conserva su propia invoice_date; lo que se
        // valida por factura es V2 (no futura) y V3 (no demasiado antigua).
        // Se deja como flag para poder volver al modo estricto sin deploy.
        //
        'reject_mixed_dates' => filter_var(env('MANIFESTS_REJECT_MIXED_DATES', false), FILTER_VALIDATE_BOOLEAN),

        // ── manifest_date_source ────────────────────────────────────────
        //
        // De dónde sale manifests.date:
        //   'upload'  (DEFAULT) → el día en que se sube el manifiesto (hoy,
        //              TZ Honduras). El manifiesto es el "lote de carga"; las
        //              facturas conservan su FechaFactura real individual.
        //   'invoice'          → se deriva de la FechaFactura del grupo
        //              (modo histórico V4; requiere homogeneidad para ser
        //              coherente, es decir reject_mixed_dates=true).
        //
        // Cambiar de fuente NO altera invoice.invoice_date — solo la fecha
        // del manifiesto usada para agrupar/reportar.
        //
        'manifest_date_source' => env('MANIFESTS_DATE_SOURCE', 'upload'),

        // ── notify_admins_on_date_rejection ─────────────────────────────
        //
        // Si los rechazos por fecha (V1/V2/V3) generan notificación in-app a
        // los admins de Hosana.
        //
        // DEFAULT: false. Un rechazo por fecha es un error de datos en ORIGEN
        // (Jaremar), no una acción que requiera a Hosana — notificar generaría
        // ruido. El rechazo SIEMPRE queda registrado en el log y en la
        // respuesta del API; solo se omite la notificación in-app. Poner true
        // si se quiere visibilidad in-app de estos rechazos.
        //
        'notify_admins_on_date_rejection' => filter_var(env('MANIFESTS_NOTIFY_ADMINS_ON_DATE_REJECTION', false), FILTER_VALIDATE_BOOLEAN),

    ],

];
