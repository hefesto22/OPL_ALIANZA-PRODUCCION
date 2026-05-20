<?php

namespace App\Helpers;

class NumberHelper
{
    private static array $unidades = [
        '', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS',
        'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
    ];

    private static array $decenas = [
        '', 'DIEZ', 'VEINTE', 'TREINTA', 'CUARENTA', 'CINCUENTA',
        'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA',
    ];

    private static array $centenas = [
        '', 'CIEN', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS',
    ];

    /**
     * Formato numérico AS400/COBOL — el que usa Jaremar en sus facturas.
     *
     * Reglas observadas en facturas físicas de Jaremar:
     *   - 0           → ".00"      (sin cero entero, solo decimales)
     *   - 0.25        → ".250"     (sin cero entero cuando |valor| < 1)
     *   - 1.5         → "1.500"    (formato estándar cuando |valor| >= 1)
     *   - 1000        → "1,000.00" (separador de miles con coma)
     *   - -15.97      → "15.97-"   (signo "-" trailing, no leading)
     *
     * Este helper existe para que el PDF de facturas se imprima
     * idéntico al formato de Jaremar — ellos asumen la responsabilidad
     * fiscal, nosotros respetamos el formato visual.
     *
     * @param  float  $value  Valor a formatear
     * @param  int  $decimals  Decimales a mostrar (default 2)
     */
    public static function as400(float $value, int $decimals = 2): string
    {
        $rounded = round($value, $decimals);

        if ($rounded == 0.0) {
            return '.'.str_repeat('0', $decimals);
        }

        $isNegative = $rounded < 0;
        $formatted = number_format(abs($rounded), $decimals);

        // |valor| < 1: quitar el "0" entero → "0.250" se convierte en ".250"
        if (abs($rounded) < 1) {
            $formatted = ltrim($formatted, '0');
        }

        return $isNegative ? $formatted.'-' : $formatted;
    }

    public static function toWords(float $amount): string
    {
        $entero = (int) floor($amount);
        $decimal = (int) round(($amount - $entero) * 100);

        $words = self::convertGroup($entero);

        if ($entero === 0) {
            $words = 'CERO';
        }

        $centavos = str_pad((string) $decimal, 2, '0', STR_PAD_LEFT);

        return trim($words).' CON '.$centavos.'/100';
    }

    private static function convertGroup(int $n): string
    {
        if ($n === 0) {
            return '';
        }
        if ($n < 20) {
            return self::$unidades[$n];
        }

        if ($n < 100) {
            $d = intdiv($n, 10);
            $u = $n % 10;

            return self::$decenas[$d].($u > 0 ? ' Y '.self::$unidades[$u] : '');
        }

        if ($n < 1000) {
            $c = intdiv($n, 100);
            $r = $n % 100;
            $centena = $c === 1 && $r > 0 ? 'CIENTO' : self::$centenas[$c];

            return $centena.($r > 0 ? ' '.self::convertGroup($r) : '');
        }

        if ($n < 1000000) {
            $miles = intdiv($n, 1000);
            $r = $n % 1000;
            $prefix = $miles === 1 ? 'MIL' : self::convertGroup($miles).' MIL';

            return $prefix.($r > 0 ? ' '.self::convertGroup($r) : '');
        }

        $millones = intdiv($n, 1000000);
        $r = $n % 1000000;
        $prefix = $millones === 1 ? 'UN MILLON' : self::convertGroup($millones).' MILLONES';

        return $prefix.($r > 0 ? ' '.self::convertGroup($r) : '');
    }
}
