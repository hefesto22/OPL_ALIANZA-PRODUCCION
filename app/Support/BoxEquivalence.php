<?php

namespace App\Support;

/**
 * Descompone una cantidad de unidades sueltas en cajas completas + unidades
 * sobrantes, según el factor de conversión (unidades por caja) del producto.
 *
 * Uso en la Sublista de Productos: un producto vendido en unidades (UN) se
 * muestra al bodeguero como "cuántas cajas equivale + cuántas unidades sueltas",
 * para que sepa qué espera recibir. Ej.: 258 unidades con factor 96 → 2 cajas
 * y 66 unidades.
 */
class BoxEquivalence
{
    /**
     * @param  int  $units  Total de unidades sueltas.
     * @param  int  $factor  Unidades por caja (conversion_factor).
     * @return array{cajas:int, sueltas:int}
     */
    public static function split(int $units, int $factor): array
    {
        $units = max(0, $units);

        // Sin factor válido (0 o 1) no hay caja que calcular: todo queda suelto.
        if ($factor <= 1) {
            return ['cajas' => 0, 'sueltas' => $units];
        }

        $cajas = intdiv($units, $factor);

        return [
            'cajas' => $cajas,
            'sueltas' => $units - $cajas * $factor,
        ];
    }

    /**
     * Descomposición Cj/Und de una LÍNEA de factura — fuente única usada por
     * la impresión ESC/P (EscpInvoiceService) y la vista HTML (invoice-hosana).
     * Antes cada consumidor duplicaba esta lógica y divergían (la vista
     * mostraba "12 | vacío" donde la impresora ya imprimía "12 | 48").
     *
     *   - CJ → cajas reales (quantity_box) + sueltas embebidas de líneas
     *     mixtas: fractions normalizado trae el TOTAL, la diferencia sobre
     *     cajas × factor son las sueltas. CJ pura → diferencia 0.
     *   - UN (u otra) → se parten las fracciones por el factor (split()).
     *
     * @param  string|null  $unitSale  UM de la línea ('CJ', 'UN', ...).
     * @param  float  $boxes  quantity_box de la línea.
     * @param  float  $fractions  quantity_fractions NORMALIZADO (total real).
     * @param  int  $factor  Unidades por caja (conversion_factor).
     * @return array{cajas:int, sueltas:int}
     */
    public static function lineBreakdown(?string $unitSale, float $boxes, float $fractions, int $factor): array
    {
        if (strtoupper((string) $unitSale) === 'CJ') {
            $cajas = (int) round($boxes);
            $factor = max(1, $factor);

            return [
                'cajas' => $cajas,
                'sueltas' => max(0, (int) round($fractions) - $cajas * $factor),
            ];
        }

        return self::split((int) round($fractions), $factor);
    }

    /**
     * Normaliza quantity_fractions al TOTAL real de fracciones de la línea,
     * incluyendo las cajas embebidas de las líneas MIXTAS de Jaremar
     * (CantidadCaja > 0 Y CantidadFracciones > 0 en la misma línea, ej.
     * bonificaciones "1 caja + 56 unidades").
     *
     * La regla es matemática, no heurística — la misma de la Sublista de
     * Productos: si fractions < cajas × factor es IMPOSIBLE que las cajas ya
     * estén incluidas (una caja completa nunca suma menos que sí misma), así
     * que se agregan. Cubre también el caso CJ puro (fractions = 0) que antes
     * se normalizaba con un if propio en cada importador.
     *
     * quantity_fractions normalizado es la fuente de verdad para: impresión
     * ESC/P (descomposición Cj/Und), disponibilidad de devoluciones y precio
     * por fracción (total / quantity_fractions).
     *
     * @param  float  $fractions  CantidadFracciones cruda del payload.
     * @param  float  $boxes  CantidadCaja cruda del payload.
     * @param  int  $factor  Unidades por caja (FactorConversion).
     * @return float Total de fracciones (cajas × factor + sueltas).
     */
    public static function totalFractions(float $fractions, float $boxes, int $factor): float
    {
        $fractions = max(0.0, $fractions);
        $boxes = max(0.0, $boxes);
        $factor = max(1, $factor);

        if ($fractions < $boxes * $factor) {
            return $boxes * $factor + $fractions;
        }

        return $fractions;
    }
}
