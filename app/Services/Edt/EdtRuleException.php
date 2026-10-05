<?php

namespace App\Services\Edt;

use RuntimeException;

/**
 * Una regla de negocio del EDT que impide la operación (fechas de ruta que
 * se cruzan, vendedor de otra bodega, cambio de bodega con historial…).
 * El mensaje es para el usuario, en español, y las pantallas lo muestran
 * tal cual.
 */
class EdtRuleException extends RuntimeException {}
