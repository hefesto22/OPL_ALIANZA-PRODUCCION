<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Impresión ESC/P del Formato Hosana (Epson LX-350)
    |--------------------------------------------------------------------------
    |
    | Réplica de la factura de texto que imprimía el sistema viejo de Jaremar:
    | layout simple, fuente residente grande y legible. Clave de este modo:
    | LARGO DE PÁGINA DINÁMICO por factura (ESC C n con n = líneas usadas) →
    | la auto tear-off de la LX-350 corta JUSTO al final del texto, sin papel
    | de más. Lo demás (calidad, oscurecido) configurable sin tocar código.
    |
    */

    // Columnas útiles por línea (a 10 cpi entran ~80 en 8").
    'chars_per_line' => (int) env('ESCP_CHARS_PER_LINE', 80),

    // Calidad: 'lq' (nítida) o 'draft' (rápida).
    'quality' => env('ESCP_QUALITY', 'lq'),

    // Oscurecido (clave con cinta gastada): doble golpe.
    'emphasized' => (bool) env('ESCP_EMPHASIZED', true),
    'double_strike' => (bool) env('ESCP_DOUBLE_STRIKE', true),

    // Fuente LQ residente: 0 = Roman, 1 = Sans Serif.
    'font' => (int) env('ESCP_FONT', 0),

    // Paso de caracteres: '10cpi' (pica, look clásico), '12cpi' (elite), 'condensed'.
    'pitch' => env('ESCP_PITCH', '10cpi'),

    // Margen izquierdo en columnas.
    'left_margin' => (int) env('ESCP_LEFT_MARGIN', 0),

    // Líneas en blanco extra al final de cada factura antes del corte
    // (espacio para la firma / tear-off). Suma al largo dinámico de página.
    'bottom_margin_lines' => (int) env('ESCP_BOTTOM_MARGIN', 2),

    // Transliterar a ASCII (quita acentos/ñ) para no depender del code page.
    'ascii_transliterate' => (bool) env('ESCP_ASCII', true),

    // Pista del nombre de la impresora para QZ Tray (busca una que lo contenga).
    // Vacío = impresora por defecto del sistema.
    'printer_name_hint' => env('ESCP_PRINTER', 'LX-350'),
];
