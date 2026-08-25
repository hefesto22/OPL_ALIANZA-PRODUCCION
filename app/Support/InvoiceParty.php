<?php

namespace App\Support;

use App\Models\Invoice;

/**
 * Normaliza los nombres de las PARTES de una factura para impresión.
 *
 * Resuelve dos rarezas reales del feed de Jaremar que solo importan en el
 * papel. Ninguna se corrige en la BD a propósito: `invoices` tiene que seguir
 * siendo comparable byte a byte contra `api_invoice_imports.raw_payload`, que
 * es el eslabón intermedio de la cadena de auditoría (ver memoria
 * project_bonificacion_faltante_verificacion_2026-07-16).
 *
 * 1. `Cliente` llega con COMILLAS LITERALES dentro del string. El valor
 *    almacenado es `"CONSUMIDOR FINAL"` — comillas incluidas — y así salía en
 *    el ticket: `Cliente: 98074115-"CONSUMIDOR FINAL"`. Se limpian al
 *    renderizar.
 *
 * 2. `EntregarA` viene SIEMPRE lleno (0 nulos y 0 vacíos en las 9,617 facturas
 *    de producción al 25/08/2026) pero en 9,514 de ellas repite exactamente el
 *    nombre del cliente. Solo aporta información en las 103 facturas a
 *    `"CONSUMIDOR FINAL"`, donde carga el negocio real que recibe la
 *    mercadería: POIT 24/7, PULPERIA JARED, MINI SUPER 1013, POLLOS DEL
 *    RANCHO. Por eso deliverTo() devuelve '' cuando no aporta nada — quien
 *    imprime no gasta una de las 44 líneas de la forma en repetir un dato.
 */
final class InvoiceParty
{
    /** Caracteres que se recortan de los extremos: espacios + la comilla de Jaremar. */
    private const TRIM_CHARS = " \t\n\r\0\x0B\"";

    /**
     * Nombre del cliente FACTURADO, sin las comillas que manda Jaremar.
     *
     * Es el ente fiscal del documento: nunca se sustituye por el destinatario
     * de la entrega, solo se le quitan las comillas.
     */
    public static function clientName(Invoice $invoice): string
    {
        return self::unquote($invoice->client_name);
    }

    /**
     * Destinatario FÍSICO de la entrega, y solo cuando aporta información
     * distinta del cliente facturado.
     *
     * Devuelve '' —y por lo tanto no se imprime— si `EntregarA` viene vacío o
     * si repite el nombre del cliente. La comparación ignora mayúsculas,
     * comillas y espacios repetidos: `"PULPERIA  JARED"` y `Pulperia Jared`
     * son el mismo negocio y no justifican una línea extra.
     */
    public static function deliverTo(Invoice $invoice): string
    {
        $deliverTo = self::unquote($invoice->deliver_to);

        if ($deliverTo === '') {
            return '';
        }

        return self::comparisonKey($deliverTo) === self::comparisonKey(self::clientName($invoice))
            ? ''
            : $deliverTo;
    }

    private static function unquote(?string $value): string
    {
        return trim((string) $value, self::TRIM_CHARS);
    }

    private static function comparisonKey(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
