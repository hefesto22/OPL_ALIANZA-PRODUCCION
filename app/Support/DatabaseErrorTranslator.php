<?php

namespace App\Support;

use Throwable;

/**
 * Traduce un error de base de datos a una causa concreta que Jaremar pueda
 * accionar sin pedirnos el log del servidor.
 *
 * Motivo (pedido de Indra el 2026-09-10): el endpoint de inserción devolvía
 * SIEMPRE el mismo cuerpo ante cualquier excepción —"Error interno al procesar
 * las facturas. El equipo técnico ha sido notificado."— y la causa real solo
 * se veía consultando `api_invoice_imports.failure_message`. En dos días eso
 * costó dos correos para dos errores distintos: un NumeroManifiesto de 24
 * caracteres contra un varchar(20) y un InvoiceId de SAP que no cabía en un
 * integer.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  QUÉ SE DEVUELVE Y QUÉ NO
 * ─────────────────────────────────────────────────────────────────────
 *  Se devuelve el fragmento de PostgreSQL que va entre "ERROR:" y
 *  "(Connection:" — ahí vive el dato útil (el valor y el tipo destino).
 *  Todo lo que sigue a "(Connection:" se DESCARTA a propósito: trae host,
 *  puerto, nombre de la base y el INSERT completo con los valores de todas
 *  las columnas. Eso es infraestructura interna y no sale del servidor.
 *
 *  El código HTTP sigue siendo 500 y no se toca: la integración de Jaremar ya
 *  discrimina por status y cambiarlo a 4xx rompería su lógica sin avisar. Lo
 *  que cambia es el CUERPO, que ahora nombra la causa.
 */
class DatabaseErrorTranslator
{
    /**
     * SQLSTATE → motivo estable que Jaremar puede discriminar en código.
     *
     * Se usa el prefijo de 5 caracteres tal cual lo reporta PostgreSQL. Los de
     * clase 22 son errores de DATO (el valor no entra en la columna) y los de
     * clase 23 son de INTEGRIDAD (choca con una restricción).
     *
     * @var array<string, string>
     */
    private const MOTIVOS = [
        '22001' => 'DATO_DEMASIADO_LARGO',
        '22003' => 'DATO_FUERA_DE_RANGO',
        '22007' => 'FECHA_CON_FORMATO_INVALIDO',
        '22P02' => 'DATO_CON_FORMATO_INVALIDO',
        '23502' => 'CAMPO_OBLIGATORIO_VACIO',
        '23503' => 'REFERENCIA_INEXISTENTE',
        '23505' => 'REGISTRO_DUPLICADO',
        '23514' => 'VALOR_NO_PERMITIDO',
    ];

    /**
     * Tabla → cómo se le nombra a Jaremar. Ellos no conocen nuestro esquema,
     * así que decir "invoice_lines" no orienta; decir "las líneas de la
     * factura" sí, porque es una parte del payload que ellos arman.
     *
     * @var array<string, string>
     */
    private const UBICACIONES = [
        'manifests' => 'el encabezado del manifiesto',
        'invoices' => 'la factura',
        'invoice_lines' => 'las líneas de la factura',
        'returns' => 'la devolución',
        'return_lines' => 'las líneas de la devolución',
    ];

    /**
     * Descompone la excepción en las piezas del cuerpo de la respuesta.
     *
     * Ante una excepción que no es de base de datos, o un SQLSTATE que no está
     * mapeado, devuelve motivo ERROR_INTERNO y el mensaje genérico de siempre:
     * nunca se filtra el texto crudo de una excepción desconocida, porque no
     * hay forma de saber qué trae adentro.
     *
     * @return array{motivo: string, mensaje: string, ubicacion: string|null, detalle: string|null}
     */
    public static function translate(Throwable $e): array
    {
        $raw = $e->getMessage();
        $sqlState = self::sqlState($raw);
        $motivo = $sqlState !== null ? (self::MOTIVOS[$sqlState] ?? null) : null;

        if ($motivo === null) {
            return [
                'motivo' => 'ERROR_INTERNO',
                'mensaje' => 'Error interno al procesar las facturas. El equipo técnico ha sido notificado.',
                'ubicacion' => null,
                'detalle' => null,
            ];
        }

        $ubicacion = self::ubicacion($raw);
        $detalle = self::detallePostgres($raw);

        return [
            'motivo' => $motivo,
            'mensaje' => self::mensaje($motivo, $ubicacion, $detalle),
            'ubicacion' => $ubicacion,
            'detalle' => $detalle,
        ];
    }

    /**
     * SQLSTATE del prefijo que antepone PDO: "SQLSTATE[22003]: ...".
     */
    private static function sqlState(string $raw): ?string
    {
        return preg_match('/SQLSTATE\[(\w{5})\]/', $raw, $m) === 1 ? $m[1] : null;
    }

    /**
     * Tabla del INSERT/UPDATE que falló, traducida a lenguaje del payload.
     */
    private static function ubicacion(string $raw): ?string
    {
        if (preg_match('/(?:insert into|update)\s+"([a-z_]+)"/i', $raw, $m) !== 1) {
            return null;
        }

        return self::UBICACIONES[$m[1]] ?? null;
    }

    /**
     * El fragmento de PostgreSQL que sigue a "ERROR:", cortado en lo primero
     * que aparezca entre "CONTEXT:" y "(Connection:".
     *
     * Los dos cortes existen por razones distintas y ninguno es cosmético:
     *
     *   "(Connection:" — a partir de ahí Laravel concatena host, puerto,
     *   nombre de la base y el INSERT entero con los valores de todas las
     *   columnas. Nada de eso puede salir en una respuesta HTTP.
     *
     *   "CONTEXT:" — PostgreSQL agrega ahí su propia traza ("unnamed portal
     *   parameter $7 = '...'"), que no le dice nada a quien lee la respuesta
     *   y solo ensucia el mensaje. Se vio en el error real del 2026-09-10.
     */
    private static function detallePostgres(string $raw): ?string
    {
        if (preg_match('/ERROR:\s*(.+?)\s*(?:CONTEXT:|\(Connection:)/s', $raw, $m) !== 1) {
            return null;
        }

        return trim($m[1]) !== '' ? trim($m[1]) : null;
    }

    /**
     * Mensaje en español, con el detalle de PostgreSQL entre paréntesis cuando
     * se pudo extraer. Se conserva el texto original de Postgres a propósito:
     * trae el valor exacto y el tipo destino, que es lo que le permite a
     * Jaremar ubicar el campo en su lado sin escribirnos.
     */
    private static function mensaje(string $motivo, ?string $ubicacion, ?string $detalle): string
    {
        $donde = $ubicacion !== null ? " al guardar {$ubicacion}" : '';

        $base = match ($motivo) {
            'DATO_DEMASIADO_LARGO' => "Un valor supera el largo máximo del campo destino{$donde}.",
            'DATO_FUERA_DE_RANGO' => "Un valor excede el rango numérico del campo destino{$donde}.",
            'FECHA_CON_FORMATO_INVALIDO' => "Una fecha no tiene un formato válido{$donde}.",
            'DATO_CON_FORMATO_INVALIDO' => "Un valor no tiene el formato que espera el campo destino{$donde}.",
            'CAMPO_OBLIGATORIO_VACIO' => "Falta un campo obligatorio{$donde}.",
            'REFERENCIA_INEXISTENTE' => "Se referencia un registro que no existe{$donde}.",
            'REGISTRO_DUPLICADO' => "Ya existe un registro con ese valor{$donde}.",
            'VALOR_NO_PERMITIDO' => "Un valor no está dentro de los permitidos{$donde}.",
            default => "No se pudo guardar el lote{$donde}.",
        };

        return $detalle !== null ? "{$base} Detalle: {$detalle}." : $base;
    }
}
