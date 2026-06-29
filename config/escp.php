<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Impresión ESC/P del Formato Hosana (Epson LX-350)
    |--------------------------------------------------------------------------
    |
    | Réplica de la factura de texto del sistema viejo de Jaremar. La forma
    | (papel continuo perforado Genial "9 1/2 X 5 1/2") mide 9.5" de ancho
    | (~21 cm útiles) × 5.5" de alto (~14 cm = la perforación), así que:
    |   - 12 cpi → 80 columnas entran en ~17 cm (caben de sobra).
    |   - 8 lpi  → 5.5" = 44 líneas por forma.
    |   - left_margin centra el contenido (~17 cm) en la forma (~21 cm).
    |   - form_mode 'fixed' + page_length_lines = alto de la forma → el form
    |     feed avanza a la perforación y la auto tear-off corta ahí.
    |
    | OJO: el "12CM" del label de la caja es la PROFUNDIDAD del cartón
    | (Medidas 30.5 x 25.6 x 12CM), NO el alto de la forma. La forma es 5.5".
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

    // Alto de la forma en LÍNEAS (modo fixed). 5.5" a 8 lpi = 44 líneas.
    // Si el corte no cae justo en la perforación, ajustar SOLO este número
    // (cada línea ≈ 0.32 cm a 8 lpi).
    'page_length_lines' => (int) env('ESCP_PAGE_LENGTH', 44),

    // Líneas en blanco al final (modo dynamic) antes del corte.
    'bottom_margin_lines' => (int) env('ESCP_BOTTOM_MARGIN', 2),

    // Calidad: 'lq' (nítida) o 'draft' (rápida).
    'quality' => env('ESCP_QUALITY', 'lq'),

    // Oscurecido (clave con cinta gastada): doble golpe.
    'emphasized' => (bool) env('ESCP_EMPHASIZED', true),
    'double_strike' => (bool) env('ESCP_DOUBLE_STRIKE', true),

    // Fuente LQ residente: 0 = Roman, 1 = Sans Serif.
    'font' => (int) env('ESCP_FONT', 0),

    // Margen izquierdo en columnas → CENTRA el contenido en la forma.
    // Contenido ~80 col; forma ~102 col útiles a 12 cpi → (102-80)/2 ≈ 10.
    // Subir = más a la derecha; bajar = más a la izquierda (cada col ≈ 0.21 cm).
    'left_margin' => (int) env('ESCP_LEFT_MARGIN', 10),

    // Transliterar a ASCII (quita acentos/ñ) para no depender del code page.
    'ascii_transliterate' => (bool) env('ESCP_ASCII', true),

    // Pista del nombre de la impresora para QZ Tray. Vacío = la por defecto.
    'printer_name_hint' => env('ESCP_PRINTER', 'LX-350'),
];
