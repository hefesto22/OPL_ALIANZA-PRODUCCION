<?php

namespace App\Enums\Edt;

use Filament\Support\Contracts\HasLabel;

/**
 * Forma de pago de una preventa. Crédito solo si el cliente lo tiene
 * habilitado (EdtOrderService lo valida).
 */
enum EdtPaymentType: string implements HasLabel
{
    case Contado = 'contado';
    case Credito = 'credito';

    public function getLabel(): string
    {
        return match ($this) {
            self::Contado => 'CONTADO',
            self::Credito => 'CRÉDITO',
        };
    }
}
