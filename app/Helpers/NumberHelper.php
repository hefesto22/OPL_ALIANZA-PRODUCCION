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

    public static function toWords(float $amount): string
    {
        $entero  = (int) floor($amount);
        $decimal = (int) round(($amount - $entero) * 100);

        $words = self::convertGroup($entero);

        if ($entero === 0) {
            $words = 'CERO';
        }

        $centavos = str_pad((string) $decimal, 2, '0', STR_PAD_LEFT);

        return trim($words) . ' CON ' . $centavos . '/100';
    }

    private static function convertGroup(int $n): string
    {
        if ($n === 0) return '';
        if ($n < 20) return self::$unidades[$n];

        if ($n < 100) {
            $d = intdiv($n, 10);
            $u = $n % 10;
            return self::$decenas[$d] . ($u > 0 ? ' Y ' . self::$unidades[$u] : '');
        }

        if ($n < 1000) {
            $c = intdiv($n, 100);
            $r = $n % 100;
            $centena = $c === 1 && $r > 0 ? 'CIENTO' : self::$centenas[$c];
            return $centena . ($r > 0 ? ' ' . self::convertGroup($r) : '');
        }

        if ($n < 1000000) {
            $miles = intdiv($n, 1000);
            $r     = $n % 1000;
            $prefix = $miles === 1 ? 'MIL' : self::convertGroup($miles) . ' MIL';
            return $prefix . ($r > 0 ? ' ' . self::convertGroup($r) : '');
        }

        $millones = intdiv($n, 1000000);
        $r        = $n % 1000000;
        $prefix   = $millones === 1 ? 'UN MILLON' : self::convertGroup($millones) . ' MILLONES';
        return $prefix . ($r > 0 ? ' ' . self::convertGroup($r) : '');
    }
}