<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Impresión ESC/P del Formato Hosana (Epson LX-350)
    |--------------------------------------------------------------------------
    |
    | Réplica de la factura de texto del sistema viejo de Jaremar. La forma
    | (papel continuo perforado) mide 18 cm de ancho × 12 cm de alto, así que:
    |   - 12 cpi → 80 columnas entran en ~17 cm (caben en 18).
    |   - 8 lpi  → más líneas por pulgada para que el contenido entre en 12 cm.
    |   - form_mode 'fixed' + page_length_lines = alto de la forma → el form
    |     feed avanza a la perforación y la auto tear-off corta ahí.
    |
    | Todo se afina sobre la impresora sin tocar código.
    |
    */

    // Columnas útiles por línea. A 12 cpi, 80 col ≈ 17 cm (cabe en 18).
    'chars_per_line' => (int) env('ESCP_CHARS_PER_LINE', 80),

    // Paso de caracteres: '12cpi' (cabe en 18 cm), '10cpi', 'condensed'.
    'pitch' => env('ESCP_PITCH', '12cpi'),

    // Interlineado: '8lpi' (más compacto, entra en 12 cm) o '6lpi'.
    'line_spacing' => env('ESCP_LINE_SPACING', '8lpi'),

    // Modo de forma:
    //   'fixed'   → papel perforado: largo de página fijo = la forma; el corte
    //               cae en la perforación. (Usar este para la forma 18x12.)
    //   'dynamic' → papel blanco continuo: largo = lo que ocupa cada factura.
    'form_mode' => env('ESCP_FORM_MODE', 'fixed'),

    // Alto de la forma en LÍNEAS (modo fixed). 12 cm a 8 lpi ≈ 38 líneas.
    // Si el corte no cae justo en la perforación, ajustar SOLO este número.
    'page_length_lines' => (int) env('ESCP_PAGE_LENGTH', 38),

    // Líneas en blanco al final (modo dynamic) antes del corte.
    'bottom_margin_lines' => (int) env('ESCP_BOTTOM_MARGIN', 2),

    // Calidad: 'lq' (nítida) o 'draft' (rápida).
    'quality' => env('ESCP_QUALITY', 'lq'),

    // Oscurecido (clave con cinta gastada): doble golpe.
    'emphasized' => (bool) env('ESCP_EMPHASIZED', true),
    'double_strike' => (bool) env('ESCP_DOUBLE_STRIKE', true),

    // Fuente LQ residente: 0 = Roman, 1 = Sans Serif.
    'font' => (int) env('ESCP_FONT', 0),

    // Margen izquierdo en columnas.
    'left_margin' => (int) env('ESCP_LEFT_MARGIN', 0),

    // Transliterar a ASCII (quita acentos/ñ) para no depender del code page.
    'ascii_transliterate' => (bool) env('ESCP_ASCII', true),

    // Pista del nombre de la impresora para QZ Tray. Vacío = la por defecto.
    'printer_name_hint' => env('ESCP_PRINTER', 'LX-350'),
];
