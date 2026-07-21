<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Detección de facturas duplicadas exactas — Sistema Distribuidora Hosana
    |--------------------------------------------------------------------------
    |
    | Contexto (incidente 2026-07): Jaremar re-emite la misma factura
    | económica (mismo cliente, mismos productos y cantidades, mismo total)
    | con número fiscal NUEVO en manifiesto NUEVO, generalmente al día
    | siguiente. Físicamente la mercadería se entrega UNA vez: la re-emisión
    | es puro papel que infla el "A Depositar" de la bodega.
    |
    | Evidencia de los datos (query de pares, 90 días):
    |   - Re-emisiones en BLOQUE: 3+ facturas idénticas hacia el mismo
    |     manifiesto destino, con 0–3 días de diferencia. 7 bloques en 3
    |     semanas, ~L. 104,000. Cero falsos positivos observados.
    |   - Pedidos legítimos repetidos: pulperías con canasta fija semanal
    |     (misma composición cada 5–7 días). NO deben bloquearse.
    |
    | Todos los umbrales ajustables desde .env sin deploy.
    |
    */

    'duplicates' => [

        // ── detection_enabled ───────────────────────────────────────────
        //
        // Interruptor maestro de la detección por huella. Apagarlo revierte
        // el importador al comportamiento previo (dedupe solo por número de
        // factura). Es la red de seguridad: si aparece un falso positivo en
        // producción, se apaga sin deploy mientras se ajustan umbrales.
        //
        'detection_enabled' => filter_var(env('INVOICES_DUPLICATE_DETECTION', true), FILTER_VALIDATE_BOOLEAN),

        // ── window_days ─────────────────────────────────────────────────
        //
        // Ventana de comparación en días (|fecha entrante − fecha existente|).
        // Las re-emisiones observadas viven en 0–3 días (incluye cadenas
        // triples: emitida el 15, re-emitida el 16 y otra vez el 18). Los
        // pedidos semanales legítimos viven en 5–7 días — la ventana NUNCA
        // debe llegar a 5 sin revisar antes los datos con la query de pares.
        //
        'window_days' => (int) env('INVOICES_DUPLICATE_WINDOW_DAYS', 3),

        // ── block_threshold ─────────────────────────────────────────────
        //
        // Número de facturas idénticas dentro de un mismo manifiesto
        // entrante a partir del cual se considera RE-EMISIÓN EN BLOQUE y el
        // manifiesto se rechaza completo (FACTURAS_DUPLICADAS_EXACTAS).
        //
        // La probabilidad de que 3+ clientes distintos repitan canastas
        // idénticas el mismo día hacia el mismo manifiesto nuevo es nula —
        // por eso el bloque se rechaza automático. Por DEBAJO del umbral el
        // match es aislado: puede ser pedido legítimo repetido, así que la
        // factura ENTRA pero marcada (duplicate_of_invoice_id) y se
        // notifica a admins para revisión humana.
        //
        'block_threshold' => (int) env('INVOICES_DUPLICATE_BLOCK_THRESHOLD', 3),

    ],

];
