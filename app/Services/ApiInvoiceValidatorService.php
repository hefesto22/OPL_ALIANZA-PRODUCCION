<?php

namespace App\Services;

class ApiInvoiceValidatorService
{
    protected array $errors = [];

    protected array $requiredInvoiceFields = [
        'Nfactura',
        'NumeroManifiesto',
        'Total',
        'LineasFactura',
        'FechaFactura',
        'Almacen',
        'Vendedorid',
        'Clienteid',
        'Cliente',
    ];

    protected array $requiredLineFields = [
        'ProductoId',
        'ProductoDesc',
        'Total',
        'NumeroLinea',
    ];

    /**
     * Largo máximo de los campos que se copian tal cual a columnas con límite
     * en BD.
     *
     * No es cosmético: sin este chequeo el valor viaja hasta el INSERT,
     * Postgres responde SQLSTATE 22001 y el controller lo convierte en un 500
     * genérico ("Error interno al procesar las facturas"). Jaremar recibe un
     * error sin causa y hay que ir a leer el log del servidor para saber qué
     * campo se pasó. Ocurrió el 2026-09-08: SAP mandó el NumeroManifiesto
     * "1234-56-78T00:00:00.000Z" — 24 caracteres — y el lote murió con un 500.
     *
     * Los valores siguen a las migraciones y tienen que moverse con ellas:
     *   NumeroManifiesto → manifests.number         varchar(20)
     *   Nfactura         → invoices.invoice_number  varchar(30)
     *
     * @var array<string, int>
     */
    protected array $maxFieldLengths = [
        'NumeroManifiesto' => 20,
        'Nfactura' => 30,
    ];

    /**
     * Valida un array de facturas recibido por API.
     *
     * A diferencia de JsonValidatorService, este permite múltiples
     * NumeroManifiesto en el mismo batch — el OPL de Jaremar puede
     * mandar facturas de distintos manifiestos en una sola llamada.
     */
    public function validate(array $invoices): bool
    {
        $this->errors = [];

        if (empty($invoices)) {
            $this->errors[] = 'El array de facturas no puede estar vacío.';

            return false;
        }

        foreach ($invoices as $index => $invoice) {
            $this->validateInvoice($invoice, $index + 1);
        }

        return empty($this->errors);
    }

    protected function validateInvoice(array $invoice, int $position): void
    {
        foreach ($this->requiredInvoiceFields as $field) {
            // Usamos array_key_exists primero para evitar "Undefined array key"
            // (PHP 8+ lo convierte en warning y en tests termina como exception).
            // Tratamos null/''/false/[] como "falta", pero permitimos el literal 0
            // porque Total puede ser 0 legítimamente en facturas canceladas.
            $value = $invoice[$field] ?? null;
            $missing = ! array_key_exists($field, $invoice)
                || ($value !== 0 && $value !== '0' && empty($value));

            if ($missing) {
                $this->errors[] = "Factura #{$position}: falta el campo obligatorio '{$field}'.";
            }
        }

        // Largo máximo. Sólo se evalúan campos presentes y con valor: si el
        // campo falta, el foreach de arriba ya reportó la ausencia y un
        // segundo error sobre el mismo campo nada más agrega ruido.
        foreach ($this->maxFieldLengths as $field => $max) {
            $value = $invoice[$field] ?? null;

            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $value = (string) $value;
            $length = mb_strlen($value);

            if ($length > $max) {
                // Se devuelve el valor recibido (acotado) porque es lo que le
                // permite a Jaremar corregir el origen sin pedirnos el log.
                $muestra = mb_substr($value, 0, 60).(mb_strlen($value) > 60 ? '…' : '');

                $this->errors[] = "Factura #{$position}: el campo '{$field}' excede el largo permitido "
                    ."({$length} caracteres, máximo {$max}). Recibido: '{$muestra}'.";
            }
        }

        if (array_key_exists('Total', $invoice) && $invoice['Total'] !== null && (! is_numeric($invoice['Total']) || $invoice['Total'] < 0)) {
            $label = $invoice['Nfactura'] ?? "#{$position}";
            $this->errors[] = "Factura {$label}: el campo 'Total' debe ser un número positivo.";
        }

        if (array_key_exists('LineasFactura', $invoice) && $invoice['LineasFactura'] !== null) {
            if (! is_array($invoice['LineasFactura']) || empty($invoice['LineasFactura'])) {
                $label = $invoice['Nfactura'] ?? "#{$position}";
                $this->errors[] = "Factura {$label}: 'LineasFactura' no puede estar vacío.";
            } else {
                foreach ($invoice['LineasFactura'] as $lineIndex => $line) {
                    if (! is_array($line)) {
                        $label = $invoice['Nfactura'] ?? "#{$position}";
                        $this->errors[] = "Factura {$label}, Línea #".($lineIndex + 1).': formato inválido.';

                        continue;
                    }
                    $this->validateLine($line, $lineIndex + 1, $invoice['Nfactura'] ?? "#{$position}");
                }
            }
        }
    }

    protected function validateLine(array $line, int $position, string $invoiceLabel): void
    {
        foreach ($this->requiredLineFields as $field) {
            if (! isset($line[$field])) {
                $this->errors[] = "Factura {$invoiceLabel}, Línea #{$position}: falta el campo '{$field}'.";
            }
        }
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getFirstError(): string
    {
        return $this->errors[0] ?? 'Error desconocido.';
    }
}
