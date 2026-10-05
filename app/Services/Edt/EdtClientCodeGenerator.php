<?php

namespace App\Services\Edt;

use App\Models\Edt\EdtClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Código automático de cliente del EDT: C-000001, C-000002…
 *
 * Sale de la secuencia de Postgres edt_client_code_seq: nextval() nunca da
 * el mismo número a dos transacciones, así que dos personas creando clientes
 * a la vez no chocan. Lo que no se usa (un alta que falla) deja un hueco;
 * no importa, no es un correlativo fiscal.
 *
 * Como el código también se puede escribir a mano, un número de la
 * secuencia puede coincidir con un código ya escrito ("C-000007"): en ese
 * caso se salta al siguiente. El unique de la tabla es la última defensa.
 */
class EdtClientCodeGenerator
{
    public const PREFIX = 'C-';

    public const DIGITS = 6;

    /** Saltos máximos por códigos ya ocupados antes de rendirse. */
    private const MAX_ATTEMPTS = 1000;

    public function next(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = self::format((int) DB::scalar("SELECT nextval('edt_client_code_seq')"));

            if (! EdtClient::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('No se encontró un código libre para el cliente después de '.self::MAX_ATTEMPTS.' intentos.');
    }

    public static function format(int $number): string
    {
        return self::PREFIX.str_pad((string) $number, self::DIGITS, '0', STR_PAD_LEFT);
    }
}
