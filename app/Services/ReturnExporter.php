<?php

namespace App\Services;

use SimpleXMLElement;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnExporter
{
    // Campos numéricos que deben serializarse con 6 decimales en JSON
    private const NUMERIC_FIELDS = ['total', 'cantidad', 'lineTotal'];

    public function __construct(
        private ReturnExportService $exportService
    ) {}

    // ─── JSON ──────────────────────────────────────────────────────────────

    public function toJson(array $data, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            // Convertir strings numéricos a floats antes de codificar
            // para que json_encode los trate como números
            $data = $this->castNumericFields($data);

            echo json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
            );
        }, $filename, [
            'Content-Type' => 'application/json',
        ]);
    }

    // ─── XML ───────────────────────────────────────────────────────────────

    public function toXml(array $data, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><devoluciones/>');

            foreach ($data as $item) {
                $dev = $xml->addChild('devolucion');
                $dev->addChild('devolucion',       htmlspecialchars($item['devolucion']));
                $dev->addChild('factura',          htmlspecialchars($item['factura']));
                $dev->addChild('clienteid',        htmlspecialchars($item['clienteid']));
                $dev->addChild('cliente',          htmlspecialchars($item['cliente']));
                $dev->addChild('fecha',            $item['fecha']);
                $dev->addChild('total',            $item['total']);
                $dev->addChild('almacen',          htmlspecialchars($item['almacen']));
                $dev->addChild('idConcepto',       htmlspecialchars($item['idConcepto']));
                $dev->addChild('concepto',         htmlspecialchars($item['concepto']));
                $dev->addChild('numeroManifiesto', htmlspecialchars($item['numeroManifiesto']));
                $dev->addChild('fechaProcesado',   $item['fechaProcesado'] ?? '');
                $dev->addChild('horaProcesado',    $item['horaProcesado'] ?? '');

                $lineas = $dev->addChild('lineasDevolucion');
                foreach ($item['lineasDevolucion'] as $linea) {
                    $l = $lineas->addChild('linea');
                    $l->addChild('productoId',  htmlspecialchars($linea['productoId']));
                    $l->addChild('producto',    htmlspecialchars($linea['producto']));
                    $l->addChild('cantidad',    $linea['cantidad']);
                    $l->addChild('numeroLinea', htmlspecialchars($linea['numeroLinea']));
                    $l->addChild('lineTotal',   $linea['lineTotal']);
                }
            }

            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = false;
            $dom->formatOutput       = true;
            $dom->loadXML($xml->asXML());
            echo $dom->saveXML();

        }, $filename, [
            'Content-Type' => 'application/xml',
        ]);
    }

    // ─── CSV ───────────────────────────────────────────────────────────────

    public function toCsv(array $data, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($data) {
            $handle = fopen('php://output', 'w');

            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                'devolucion', 'factura', 'clienteid', 'cliente', 'fecha',
                'total', 'almacen', 'idConcepto', 'concepto', 'numeroManifiesto',
                'fechaProcesado', 'horaProcesado',
                'productoId', 'producto', 'cantidad', 'numeroLinea', 'lineTotal',
            ]);

            foreach ($data as $item) {
                if (empty($item['lineasDevolucion'])) {
                    fputcsv($handle, [
                        $item['devolucion'], $item['factura'], $item['clienteid'],
                        $item['cliente'], $item['fecha'], $item['total'],
                        $item['almacen'], $item['idConcepto'], $item['concepto'],
                        $item['numeroManifiesto'], $item['fechaProcesado'] ?? '',
                        $item['horaProcesado'] ?? '',
                        '', '', '', '', '',
                    ]);
                } else {
                    foreach ($item['lineasDevolucion'] as $linea) {
                        fputcsv($handle, [
                            $item['devolucion'], $item['factura'], $item['clienteid'],
                            $item['cliente'], $item['fecha'], $item['total'],
                            $item['almacen'], $item['idConcepto'], $item['concepto'],
                            $item['numeroManifiesto'], $item['fechaProcesado'] ?? '',
                            $item['horaProcesado'] ?? '',
                            $linea['productoId'], $linea['producto'], $linea['cantidad'],
                            $linea['numeroLinea'], $linea['lineTotal'],
                        ]);
                    }
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ─── Helper ────────────────────────────────────────────────────────────

    /**
     * Convierte los campos numéricos (guardados como strings con 6 decimales)
     * a floats para que json_encode + JSON_PRESERVE_ZERO_FRACTION los serialice
     * correctamente como números con decimales.
     */
    private function castNumericFields(array $data): array
    {
        return array_map(function (array $item) {
            foreach (self::NUMERIC_FIELDS as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    $item[$field] = (float) $item[$field];
                }
            }

            $item['lineasDevolucion'] = array_map(function (array $linea) {
                foreach (self::NUMERIC_FIELDS as $field) {
                    if (isset($linea[$field]) && is_string($linea[$field])) {
                        $linea[$field] = (float) $linea[$field];
                    }
                }
                return $linea;
            }, $item['lineasDevolucion']);

            return $item;
        }, $data);
    }
}