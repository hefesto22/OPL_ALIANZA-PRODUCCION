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
            if (empty($invoice[$field]) && $invoice[$field] !== 0) {
                $this->errors[] = "Factura #{$position}: falta el campo obligatorio '{$field}'.";
            }
        }

        if (isset($invoice['Total']) && (!is_numeric($invoice['Total']) || $invoice['Total'] < 0)) {
            $label = $invoice['Nfactura'] ?? "#{$position}";
            $this->errors[] = "Factura {$label}: el campo 'Total' debe ser un número positivo.";
        }

        if (isset($invoice['LineasFactura'])) {
            if (!is_array($invoice['LineasFactura']) || empty($invoice['LineasFactura'])) {
                $label = $invoice['Nfactura'] ?? "#{$position}";
                $this->errors[] = "Factura {$label}: 'LineasFactura' no puede estar vacío.";
            } else {
                foreach ($invoice['LineasFactura'] as $lineIndex => $line) {
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