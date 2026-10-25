<?php

namespace App\Enums\Edt;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Estado de una preventa del EDT. Los valores son los del CHECK
 * edt_orders_status_valid.
 *
 *   BORRADOR → ENVIADO → EN REVISIÓN → APROBADO (pasa a facturación)
 *                    ↘ ANULADO (el vendedor)   ↘ RECHAZADO (la bodega)
 */
enum EdtOrderStatus: string implements HasColor, HasLabel
{
    /** El vendedor lo está armando; nadie más lo ve. */
    case Borrador = 'borrador';

    /** Preventa enviada; el vendedor todavía la puede corregir o anular. */
    case Enviado = 'enviado';

    /** La bodega ya ajustó alguna línea; el vendedor ya no la toca. */
    case EnRevision = 'en_revision';

    /** La bodega la aprobó: lista para facturar. */
    case Aprobado = 'aprobado';

    /** La bodega la rechazó (con motivo). */
    case Rechazado = 'rechazado';

    /** El vendedor la anuló antes de la revisión. */
    case Anulado = 'anulado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Borrador => 'BORRADOR',
            self::Enviado => 'ENVIADO',
            self::EnRevision => 'EN REVISIÓN',
            self::Aprobado => 'APROBADO',
            self::Rechazado => 'RECHAZADO',
            self::Anulado => 'ANULADO',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Borrador => 'gray',
            self::Enviado => 'info',
            self::EnRevision => 'warning',
            self::Aprobado => 'success',
            self::Rechazado, self::Anulado => 'danger',
        };
    }

    /** El vendedor lo puede cambiar o anular. */
    public function isEditableBySeller(): bool
    {
        return in_array($this, [self::Borrador, self::Enviado], true);
    }

    /** La bodega lo puede ajustar, aprobar o rechazar. */
    public function isReviewable(): bool
    {
        return in_array($this, [self::Enviado, self::EnRevision], true);
    }
}
