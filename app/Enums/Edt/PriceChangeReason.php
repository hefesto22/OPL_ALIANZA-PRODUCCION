<?php

namespace App\Enums\Edt;

use Filament\Support\Contracts\HasLabel;

/**
 * Motivo de cada fila del historial de precios del EDT.
 *
 * Los valores son los que acepta el CHECK edt_price_history_reason_valid;
 * si se agrega uno aquí, hay que agregarlo también en una migración.
 */
enum PriceChangeReason: string implements HasLabel
{
    /** Alta del producto en el catálogo. */
    case Creacion = 'creacion';

    /** El proveedor mandó una lista de precios nueva. */
    case ListaProveedor = 'lista_proveedor';

    /** La factura de compra vino con otro precio (la hoja "Calculo precios"). */
    case FacturaCompra = 'factura_compra';

    /** Se había capturado mal. */
    case Correccion = 'correccion';

    /** Cambio de datos que mueven el precio hecho fuera de "Cambiar precio". */
    case Edicion = 'edicion';

    /** Cambió el descuento de operación del proveedor. */
    case DescuentoProveedor = 'descuento_proveedor';

    /** Cambiaron las escalas mayoristas. */
    case Escalas = 'escalas';

    /** Carga del catálogo desde el Excel del cliente. */
    case CargaInicial = 'carga_inicial';

    public function getLabel(): string
    {
        return match ($this) {
            self::Creacion => 'CREACIÓN',
            self::ListaProveedor => 'LISTA NUEVA DEL PROVEEDOR',
            self::FacturaCompra => 'FACTURA DE COMPRA',
            self::Correccion => 'CORRECCIÓN',
            self::Edicion => 'EDICIÓN',
            self::DescuentoProveedor => 'DESCUENTO DEL PROVEEDOR',
            self::Escalas => 'ESCALAS MAYORISTAS',
            self::CargaInicial => 'CARGA INICIAL',
        };
    }

    /**
     * Los que el usuario elige en la acción "Cambiar precio".
     *
     * @return array<string, string>
     */
    public static function manualOptions(): array
    {
        return collect([self::ListaProveedor, self::FacturaCompra, self::Correccion])
            ->mapWithKeys(fn (self $reason): array => [$reason->value => $reason->getLabel()])
            ->all();
    }
}
