<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * Huella canónica de una factura para detectar RE-EMISIONES de Jaremar:
 * la misma factura económica reenviada con número fiscal nuevo, en
 * manifiesto nuevo, generalmente al día siguiente.
 *
 * La huella se calcula sobre lo que NO cambia entre re-emisiones:
 *
 *   client_id + líneas ordenadas (product_id : fracciones_totales) + total
 *
 * Y excluye deliberadamente lo que SÍ cambia:
 *   - invoice_number / jaremar_id / fechas → Jaremar los regenera.
 *   - montos POR LÍNEA → Jaremar recalcula el redondeo al re-emitir
 *     (observado en factura real: Imp 360.62 vs 360.63, total de línea
 *     2,764.79 vs 2,764.80). El total de FACTURA sí coincide y sí se usa.
 *
 * Regla de oro: fromPayload() y fromInvoice() DEBEN producir el mismo hash
 * para la misma factura. Por eso ambos caminos pasan por la misma
 * normalización numérica (num()) y la misma normalización de fracciones
 * (BoxEquivalence::totalFractions, que el importador ya aplica al insertar).
 * Cualquier cambio aquí exige re-correr invoices:backfill-fingerprints.
 */
class InvoiceFingerprint
{
    /**
     * Huella desde el payload crudo de Jaremar (formato API insertar).
     *
     * @param  array<string, mixed>  $data  Factura del payload (Nfactura, LineasFactura, ...)
     * @return string|null md5 de 32 chars, o null si faltan client_id o líneas.
     */
    public static function fromPayload(array $data): ?string
    {
        $clientId = trim((string) ($data['Clienteid'] ?? ''));

        $lines = [];
        foreach ($data['LineasFactura'] ?? [] as $line) {
            $fractions = BoxEquivalence::totalFractions(
                (float) ($line['CantidadFracciones'] ?? 0),
                (float) ($line['CantidadCaja'] ?? 0),
                max(1, (int) ($line['FactorConversion'] ?? 1)),
            );

            $lines[] = self::lineComponent((string) ($line['ProductoId'] ?? ''), $fractions);
        }

        return self::hash($clientId, $lines, (float) ($data['Total'] ?? 0));
    }

    /**
     * Huella desde una factura persistida (backfill / auditoría).
     *
     * quantity_fractions en BD ya viene normalizado por el importador
     * (total real de fracciones), así que NO se re-aplica totalFractions.
     */
    public static function fromInvoice(Invoice $invoice): ?string
    {
        $invoice->loadMissing('lines');

        $lines = $invoice->lines
            ->map(fn ($line) => self::lineComponent(
                (string) $line->product_id,
                (float) $line->quantity_fractions,
            ))
            ->all();

        return self::hash(
            trim((string) $invoice->client_id),
            $lines,
            (float) $invoice->getRawOriginal('total'),
        );
    }

    /**
     * Componente canónico de una línea: "product_id:fracciones".
     */
    protected static function lineComponent(string $productId, float $fractions): string
    {
        return trim($productId).':'.self::num($fractions);
    }

    /**
     * @param  list<string>  $lines
     */
    protected static function hash(string $clientId, array $lines, float $total): ?string
    {
        // Sin cliente o sin líneas no hay huella confiable — la factura se
        // excluye de la detección en vez de arriesgar un match espurio.
        if ($clientId === '' || $lines === []) {
            return null;
        }

        // Orden canónico: el orden de líneas de Jaremar no es estable entre
        // emisiones. sort() cubre también productos repetidos (línea pagada
        // + línea de bonificación del mismo product_id).
        sort($lines, SORT_STRING);

        return md5($clientId.'|'.implode('|', $lines).'|'.self::num($total));
    }

    /**
     * Formato numérico canónico: hasta 4 decimales, sin ceros colgantes.
     *
     * Ambos caminos (float del payload y decimal string de Postgres) pasan
     * por aquí, garantizando "24.0" ≡ "24.0000" ≡ 24 → "24".
     */
    protected static function num(float $value): string
    {
        $formatted = number_format($value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
