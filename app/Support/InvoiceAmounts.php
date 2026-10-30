<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceLine;

/**
 * Desglose fiscal del pie de factura del Formato Hosana (matriz LX-350 y su
 * vista previa HTML), en el orden que pidió la distribuidora:
 *
 *   Importe Gravado 15% · Importe Gravado 18% · Importe Exento · SubTotal ·
 *   Impuesto 15% · Impuesto 18% · TOTAL
 *
 * Todo sale de lo que manda Jaremar; aquí solo se reparte:
 *
 *   - Exento, ISV 15 %, ISV 18 % y Total: tal cual del encabezado.
 *   - SubTotal = Exento + Exonerado + Gravado, igual que siempre tuvo esta
 *     factura y que la fila "TOTAL A PAGAR" del formato Jaremar.
 *   - Jaremar manda UN solo Importe Gravado. Se parte en 15 % / 18 % con el ISV
 *     de la factura: si no hay ISV 18 % (lo normal: el 18 % es para cerveza,
 *     licor y cigarros), todo es gravado al 15 %, y al revés. Si trae los dos,
 *     el 18 % es la suma de las líneas con ISV 18 % y el resto es 15 %.
 *
 * Exonerado y Descuento NO se imprimen (decisión de Mauricio, 30/10/2026).
 * Jaremar no manda un descuento confiable: lo mete en ImporteGravado_Desc, la
 * bonificación exenta la resta del exento sin anotarla, ImporteExento_Desc trae
 * basura y DescuentosRebajas viene siempre en 0 (19,821 facturas revisadas). Por
 * eso SubTotal + Impuestos no siempre da el TOTAL impreso, igual que antes de
 * este cambio: el TOTAL es el de Jaremar.
 *
 * Ver memory project_invoice_pdf_jaremar_format.
 */
final class InvoiceAmounts
{
    /**
     * @return array{
     *     gravado15: float,
     *     gravado18: float,
     *     exento: float,
     *     subtotal: float,
     *     isv15: float,
     *     isv18: float,
     *     total: float,
     * }
     */
    public static function breakdown(Invoice $invoice): array
    {
        $exento = self::money($invoice->importe_excento);
        $exonerado = self::money($invoice->importe_exonerado);
        $gravado = self::money($invoice->importe_gravado);
        $isv15 = self::money($invoice->isv15);
        $isv18 = self::money($invoice->isv18);

        $subtotal = round($exento + $exonerado + $gravado, 2);

        [$gravado15, $gravado18] = self::splitGravado($invoice, $gravado, $isv15, $isv18);

        return [
            'gravado15' => $gravado15,
            'gravado18' => $gravado18,
            'exento' => $exento,
            'subtotal' => $subtotal,
            'isv15' => $isv15,
            'isv18' => $isv18,
            'total' => self::money($invoice->total),
        ];
    }

    /**
     * @return array{0: float, 1: float} [gravado 15 %, gravado 18 %]
     */
    private static function splitGravado(Invoice $invoice, float $gravado, float $isv15, float $isv18): array
    {
        if ($isv18 <= 0.0) {
            return [$gravado, 0.0];
        }

        if ($isv15 <= 0.0) {
            return [0.0, $gravado];
        }

        $base18 = round((float) $invoice->lines
            ->filter(fn (InvoiceLine $line): bool => (float) $line->tax18 > 0)
            ->sum(fn (InvoiceLine $line): float => (float) $line->subtotal), 2);

        // Sin líneas que lo expliquen: la base sale del propio impuesto.
        if ($base18 <= 0.0) {
            $base18 = round($isv18 / 0.18, 2);
        }

        $base18 = min($base18, $gravado);

        return [round($gravado - $base18, 2), $base18];
    }

    private static function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}
