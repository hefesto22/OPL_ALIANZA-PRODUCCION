<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceLine;

/**
 * Desglose fiscal del pie de factura del Formato Hosana (matriz LX-350 y su
 * vista previa HTML), en el orden que pidió la distribuidora:
 *
 *   Importe Exonerado · Importe Gravado 15% · Importe Gravado 18% ·
 *   Importe Exento · SubTotal · Descuento · Impuesto 15% · Impuesto 18% · TOTAL
 *
 * Todo sale de lo que manda Jaremar; aquí solo se reparte y se suma:
 *
 *   - Exento, Exonerado, ISV 15 %, ISV 18 % y Total: tal cual del encabezado.
 *   - SubTotal = Exento + Exonerado + Gravado (igual que antes en esta factura
 *     y que la fila "TOTAL A PAGAR" del formato Jaremar).
 *   - Jaremar manda UN solo Importe Gravado. Se parte en 15 % / 18 % con el ISV
 *     de la factura: si no hay ISV 18 % (lo normal: el 18 % es para cerveza,
 *     licor y cigarros), todo es gravado al 15 %, y al revés. Si trae los dos,
 *     el 18 % es la suma de las líneas con ISV 18 % y el resto es 15 %.
 *   - Descuento = valor de la mercadería bonificada, con la MISMA regla del
 *     formato Jaremar (invoice-pdf): floor(precio × cantidad) por línea "B".
 *     Por eso en una factura solo de bonificación se lee
 *     SubTotal − Descuento + ISV = TOTAL (el cliente solo paga el ISV).
 *
 * Ver memory project_invoice_pdf_jaremar_format y
 * project_isv_imposible_jaremar_2026-07-17.
 */
final class InvoiceAmounts
{
    /**
     * @return array{
     *     exonerado: float,
     *     gravado15: float,
     *     gravado18: float,
     *     exento: float,
     *     subtotal: float,
     *     descuento: float,
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

        [$gravado15, $gravado18] = self::splitGravado($invoice, $gravado, $isv15, $isv18);

        return [
            'exonerado' => $exonerado,
            'gravado15' => $gravado15,
            'gravado18' => $gravado18,
            'exento' => $exento,
            'subtotal' => round($exento + $exonerado + $gravado, 2),
            'descuento' => self::bonusDiscount($invoice),
            'isv15' => $isv15,
            'isv18' => $isv18,
            'total' => self::money($invoice->total),
        ];
    }

    /**
     * Valor de la mercadería bonificada (líneas tipo "B"): floor(precio ×
     * cantidad) al centavo, como lo imprime Jaremar. El round previo a 6
     * decimales evita que un 5,298.09 guardado como 5,298.0899999 en coma
     * flotante pierda un centavo al truncar.
     */
    public static function bonusDiscount(Invoice $invoice): float
    {
        $total = 0.0;

        foreach ($invoice->lines as $line) {
            if (strtoupper((string) ($line->product_type ?? '')) !== 'B') {
                continue;
            }

            $exact = (float) $line->price * (float) $line->quantity_decimal;
            $total += floor(round($exact * 100, 6)) / 100;
        }

        return round($total, 2);
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
