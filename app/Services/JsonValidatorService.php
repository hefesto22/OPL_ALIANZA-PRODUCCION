<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class JsonValidatorService
{
    protected array $errors = [];

    // ─── Campos obligatorios por factura ──────────────────────
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

    // ─── Campos obligatorios por línea de factura ──────────────
    protected array $requiredLineFields = [
        'ProductoId',
        'ProductoDesc',
        'Total',
        'NumeroLinea',
    ];

    // ─── Almacenes válidos para Hosana ─────────────────────────
    protected array $validWarehouses = ['OAC', 'OAO', 'OAS'];

    /**
     * Validar el contenido del JSON antes de importar.
     */
    public function validate(string $content): bool
    {
        $this->errors = [];

        // 1. ¿Es JSON válido?
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = 'El archivo no es un JSON válido: ' . json_last_error_msg();
            return false;
        }

        // 2. ¿Es un array?
        if (!is_array($data) || empty($data)) {
            $this->errors[] = 'El JSON debe ser un array de facturas y no puede estar vacío.';
            return false;
        }

        // 3. ¿Todas las facturas tienen el mismo NumeroManifiesto?
        $manifestNumbers = array_unique(array_column($data, 'NumeroManifiesto'));
        if (count($manifestNumbers) > 1) {
            $this->errors[] = 'El JSON contiene facturas de múltiples manifiestos: ' . implode(', ', $manifestNumbers);
            return false;
        }

        // 4. Validar cada factura
        foreach ($data as $index => $invoice) {
            $this->validateInvoice($invoice, $index + 1);
        }

        return empty($this->errors);
    }

    /**
     * Validar una factura individual.
     */
    protected function validateInvoice(array $invoice, int $position): void
    {
        // Identificador seguro para mensajes de error (Nfactura puede faltar)
        $invoiceLabel = (string) ($invoice['Nfactura'] ?? "#{$position}");

        // Campos obligatorios
        foreach ($this->requiredInvoiceFields as $field) {
            if (empty($invoice[$field])) {
                $this->errors[] = "Factura #{$position}: falta el campo obligatorio '{$field}'.";
            }
        }

        // Total debe ser numérico y positivo
        if (isset($invoice['Total']) && (!is_numeric($invoice['Total']) || $invoice['Total'] < 0)) {
            $this->errors[] = "Factura #{$position} ({$invoiceLabel}): el campo 'Total' debe ser un número positivo.";
        }

        // LineasFactura debe ser array no vacío
        if (isset($invoice['LineasFactura'])) {
            if (!is_array($invoice['LineasFactura']) || empty($invoice['LineasFactura'])) {
                $this->errors[] = "Factura #{$position} ({$invoiceLabel}): 'LineasFactura' no puede estar vacío.";
            } else {
                foreach ($invoice['LineasFactura'] as $lineIndex => $line) {
                    $this->validateLine($line, $lineIndex + 1, $invoiceLabel);
                }
            }
        }
    }

    /**
     * Validar una línea de factura.
     */
    protected function validateLine(array $line, int $position, string $invoiceNumber): void
    {
        foreach ($this->requiredLineFields as $field) {
            if (!isset($line[$field])) {
                $this->errors[] = "Factura {$invoiceNumber}, Línea #{$position}: falta el campo '{$field}'.";
            }
        }
    }

    /**
     * Detectar si el manifiesto ya existe en BD.
     */
    public function isDuplicate(string $content): bool
    {
        $data = json_decode($content, true);
        if (!$data) return false;

        $manifestNumber = $data[0]['NumeroManifiesto'] ?? null;
        if (!$manifestNumber) return false;

        return \App\Models\Manifest::where('number', $manifestNumber)->exists();
    }

    /**
     * Obtener el número de manifiesto del JSON.
     */
    public function getManifestNumber(string $content): ?string
    {
        $data = json_decode($content, true);
        return $data[0]['NumeroManifiesto'] ?? null;
    }

    /**
     * Contar cuántas facturas son de bodegas Hosana.
     */
    public function countValidWarehouses(string $content): int
    {
        $data = json_decode($content, true);
        return collect($data)
            ->filter(fn($i) => in_array($i['Almacen'] ?? '', $this->validWarehouses))
            ->count();
    }

    /**
     * Obtener errores de validación.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Obtener primer error como string.
     */
    public function getFirstError(): string
    {
        return $this->errors[0] ?? 'Error desconocido.';
    }
}