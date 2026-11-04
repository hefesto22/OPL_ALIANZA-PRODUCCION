<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Cuándo ve Jaremar las devoluciones (Configuración → Devoluciones).
 * Los valores son los del CHECK return_settings_mode_valid.
 */
enum ReturnPublicationMode: string implements HasDescription, HasLabel
{
    /** Paquete completo del manifiesto al cerrar su ventana (contrato de julio 2026). */
    case Window = 'window';

    /** Cada devolución por separado, N días después de registrada (0 = al momento). */
    case AfterRegistration = 'after_registration';

    public function getLabel(): string
    {
        return match ($this) {
            self::Window => 'Al cerrar la ventana del manifiesto',
            self::AfterRegistration => 'Cada devolución, un tiempo después de registrarla',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Window => 'Jaremar recibe todas las devoluciones del manifiesto juntas cuando se acaban los días para registrar. Es como funciona hoy.',
            self::AfterRegistration => 'Jaremar ve cada devolución por separado: al momento o los días que elijas después de registrada, aunque el manifiesto siga abierto.',
        };
    }
}
