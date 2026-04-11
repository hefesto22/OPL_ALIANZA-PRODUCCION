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
            $value   = $invoice[$field] ?? null;
            $missing = ! array_key_exists($field, $invoice)
                || ($value !== 0 && $value !== '0' && empty($value));

            if ($missing) {
                $this->errors[] = "Factura #{$position}: falta el campo obligatorio '{$field}'.";
            }
        }

        if (array_key_exists('Total', $invoice) && $invoice['Total'] !== null && (!is_numeric($invoice['Total']) || $invoice['Total'] < 0)) {
            $label = $invoice['Nfactura'] ?? "#{$position}";
            $this->errors[] = "Factura {$label}: el campo 'Total' debe ser un número positivo.";
        }

        if (array_key_exists('LineasFactura', $invoice) && $invoice['LineasFactura'] !== null) {
            if (!is_array($invoice['LineasFactura']) || empty($invoice['LineasFactura'])) {
                $label = $invoice['Nfactura'] ?? "#{$position}";
                $this->errors[] = "Factura {$label}: 'LineasFactura' no puede estar vacío.";
            } else {
                foreach ($invoice['LineasFactura'] as $lineIndex => $line) {
                    if (!is_array($line)) {
                        $label = $invoice['Nfactura'] ?? "#{$position}";
                        $this->errors[] = "Factura {$label}, Línea #" . ($lineIndex + 1) . ": formato inválido.";
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
            if (!isset($line[$field])) {
                $this->errors[] = "Factura {$invoiceLabel}, Línea #{$position}: falta el campo '{$field}'.";
            }
        }
    }

    public function getErrors(): array  { return $this->errors; }
    public function getFirstError(): string { return $this->errors[0] ?? 'Error desconocido.'; }
}