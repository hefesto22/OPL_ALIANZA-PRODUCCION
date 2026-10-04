<?php

namespace App\Support\Edt;

/**
 * Constantes del módulo EDT.
 *
 * El EDT vive dentro de la misma app pero funciona aparte del flujo de
 * Jaremar: tablas propias (prefijo edt_), modelos propios (App\Models\Edt)
 * y su propio grupo en el menú. Los pocos puntos de contacto con el código
 * compartido (el panel y la limpieza de la bitácora) leen estas constantes
 * para que el nombre del grupo y el de la bitácora existan en un solo lugar.
 */
final class EdtModule
{
    /**
     * Grupo del menú lateral donde se agrupan todas las pantallas del EDT.
     */
    public const NAVIGATION_GROUP = 'EDT Sistema';

    /**
     * Nombre de la bitácora (activity_log.log_name) de los modelos del EDT.
     *
     * Es permanente: activitylog:prune NO la borra a los 90 días, porque el
     * historial de cambios del catálogo (proveedores, productos, precios) se
     * consulta meses después. Los catálogos cambian poco, así que el volumen
     * que se acumula es mínimo comparado con la bitácora de Jaremar.
     */
    public const LOG_NAME = 'edt';

    private function __construct() {}
}
