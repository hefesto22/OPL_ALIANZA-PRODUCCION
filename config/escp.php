<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Impresión ESC/P para matriz de punto (Epson LX-350)
    |--------------------------------------------------------------------------
    |
    | La LX-350 es 9 agujas, carro angosto (80 columnas a 10 cpi). Para que
    | el formato ancho de la factura calce, imprimimos en CONDENSADA (~17 cpi,
    | hasta ~137 columnas en 8"). Para máxima oscuridad —incluso con cinta
    | gastada— combinamos calidad NLQ + emphasized (ESC E) + double-strike
    | (ESC G): la impresora golpea cada carácter dos veces.
    |
    | Estos valores se afinan tras la prueba física. Cambiarlos NO requiere
    | tocar código.
    |
    */

    // Columnas útiles por línea (condensada en 8"). El layout se diseña por
    // debajo de este tope; las líneas más largas se truncan para no envolver.
    'chars_per_line' => (int) env('ESCP_CHARS_PER_LINE', 136),

    // Largo de página en líneas (a 6 lpi: 66 = 11"). Ajustar al papel real.
    'page_length_lines' => (int) env('ESCP_PAGE_LENGTH_LINES', 66),

    // Calidad: 'lq' (carta, nítida) o 'draft' (rápida). Para facturas: lq.
    'quality' => env('ESCP_QUALITY', 'lq'),

    // Doble golpe: oscurece el texto (clave con cinta gastada).
    'emphasized' => (bool) env('ESCP_EMPHASIZED', true),
    'double_strike' => (bool) env('ESCP_DOUBLE_STRIKE', true),

    // Fuente LQ residente: 0 = Roman, 1 = Sans Serif.
    'font' => (int) env('ESCP_FONT', 0),

    // Paso de caracteres base: 'condensed' (recomendado), '10cpi', '12cpi'.
    'pitch' => env('ESCP_PITCH', 'condensed'),

    // Margen izquierdo en columnas.
    'left_margin' => (int) env('ESCP_LEFT_MARGIN', 0),

    // Transliterar a ASCII (quita acentos/ñ) para evitar caracteres
    // garabateados por code page. Las facturas van en mayúsculas sin acento.
    'ascii_transliterate' => (bool) env('ESCP_ASCII', true),

    // Form feed (salto de página) entre facturas.
    'form_feed_between_invoices' => (bool) env('ESCP_FORM_FEED', true),

    // Pista del nombre de la impresora para QZ Tray (busca una cuyo nombre
    // contenga este texto). Vacío = usar la impresora por defecto del sistema.
    'printer_name_hint' => env('ESCP_PRINTER', 'LX-350'),
];
