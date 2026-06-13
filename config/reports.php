<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Límite de filas por reporte PDF
    |--------------------------------------------------------------------------
    |
    | Salvaguarda de memoria para los reportes de PrintReportsController.
    | Antes de materializar la query con ->get(), se cuenta cuántas filas
    | devolvería y se aborta con 422 si supera este límite. Protege al proceso
    | PHP y al worker de Browsershot/Chromium ante rangos demasiado amplios.
    |
    | 0 o negativo = sin límite (útil en desarrollo).
    |
    | Se accede vía config('reports.max_rows') — nunca env() directo en código
    | de aplicación, porque rompe con `config:cache` (regla sección 16).
    |
    */

    'max_rows' => (int) env('REPORTS_MAX_ROWS', 5000),

];
