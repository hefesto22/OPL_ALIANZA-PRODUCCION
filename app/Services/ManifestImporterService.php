<?php

namespace App\Services;

use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class ManifestImporterService
{
    protected array $warnings = [];

    protected array $unknownWarehouses = [];

    /** Cache de bodegas: ['OAC' => 1, 'OAO' => 2, 'OAS' => 3] */
    protected array $warehouseMap = [];

    protected const INVOICE_COLUMNS = [
        'manifest_id', 'warehouse_id', 'status', 'jaremar_id',
        'invoice_number', 'lx_number', 'order_number',
        'invoice_date', 'due_date', 'print_limit_date',
        'seller_id', 'seller_name', 'client_id', 'client_name', 'client_rtn',
        'deliver_to', 'department', 'municipality', 'neighborhood',
        'address', 'phone', 'longitude', 'latitude', 'route_number',
        'cai', 'range_start', 'range_end', 'payment_type', 'credit_days',
        'invoice_type', 'invoice_status', 'matriz_address', 'branch_address',
        'importe_excento', 'importe_exento_desc', 'importe_exento_isv18',
        'importe_exento_isv15', 'importe_exento_total',
        'importe_exonerado', 'importe_exonerado_desc', 'importe_exonerado_isv18',
        'importe_exonerado_isv15', 'importe_exonerado_total',
        'importe_gravado', 'importe_gravado_desc', 'importe_gravado_isv18',
        'importe_gravado_isv15', 'importe_gravado_total',
        'discounts', 'isv18', 'isv15', 'total',
        'created_at', 'updated_at',
    ];

    protected const LINE_COLUMNS = [
        'invoice_id', 'jaremar_line_id', 'invoice_jaremar_id', 'line_number',
        'product_id', 'product_description', 'product_type', 'unit_sale',
        'quantity_fractions', 'quantity_decimal', 'quantity_box',
        'quantity_min_sale', 'conversion_factor',
        'cost', 'price', 'price_min_sale',
        'subtotal', 'discount', 'discount_percent',
        'tax', 'tax_percent', 'tax18', 'total',
        'weight', 'volume',
        'created_at', 'updated_at',
    ];

    public function __construct()
    {
        $this->warehouseMap = Warehouse::pluck('id', 'code')->toArray();
    }

    // ─── Manifiesto ───────────────────────────────────────────

    public function createManifest(array $rawData, int $userId): Manifest
    {
        // firstOrFail() lanzaría un ModelNotFoundException genérico que es
        // difícil de diagnosticar en producción. Un mensaje descriptivo permite
        // al administrador saber exactamente qué configurar para solucionarlo.
        $supplier = Supplier::where('is_active', true)->first()
            ?? throw new \RuntimeException(
                'No se encontró ningún proveedor activo en el sistema. '.
                'Configure al menos un proveedor activo antes de importar manifiestos.'
            );

        $first = collect($rawData)->first();

        return Manifest::create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => null,
            'number' => $first['NumeroManifiesto'],
            'date' => now()->toDateString(),
            'status' => 'imported',
            'raw_json' => $rawData,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    // ─── Chunk: INSERT facturas + INSERT líneas ─────────────────

    protected const LINE_CHUNK_SIZE = 500;

    public function importChunk(Manifest $manifest, array $chunk): void
    {
        $now = now()->toDateTimeString();

        // 1. Preparar filas de facturas
        $invoiceRows = [];
        foreach ($chunk as $invoiceData) {
            $warehouseCode = $invoiceData['Almacen'] ?? null;
            $warehouseId = $this->warehouseMap[$warehouseCode] ?? null;

            if (! $warehouseId && $warehouseCode) {
                if (! in_array($warehouseCode, $this->unknownWarehouses)) {
                    $this->unknownWarehouses[] = $warehouseCode;
                }
            }

            $invoiceRows[] = [
                $manifest->id,
                $warehouseId,
                $warehouseId ? 'imported' : 'pending_warehouse',
                $invoiceData['Id'] ?? null,
                $invoiceData['Nfactura'],
                $invoiceData['NumeroFacturaLX'] ?? null,
                $invoiceData['NumeroPedido'] ?? null,
                $this->parseDate($invoiceData['FechaFactura']),
                $this->parseDate($invoiceData['FechaVencimiento'] ?? null),
                $this->parseDate($invoiceData['FechaLimImpre'] ?? null),
                $invoiceData['Vendedorid'] ?? null,
                $invoiceData['Vendedor'] ?? null,
                $invoiceData['Clienteid'] ?? null,
                $invoiceData['Cliente'],
                $invoiceData['Rtn'] ?? null,
                $invoiceData['EntregarA'] ?? null,
                $invoiceData['Depto'] ?? null,
                $invoiceData['Municipio'] ?? null,
                $invoiceData['Barrio'] ?? null,
                $invoiceData['Direccion'] ?? null,
                $invoiceData['Tel'] ?? null,
                $invoiceData['Longitud'] ?? null,
                $invoiceData['Latitud'] ?? null,
                trim($invoiceData['NumeroRuta'] ?? ''),
                $invoiceData['Cai'] ?? null,
                $invoiceData['Rinicial'] ?? null,
                $invoiceData['Rfinal'] ?? null,
                $invoiceData['TipoPago'] ?? null,
                $invoiceData['DiasCred'] ?? 0,
                $invoiceData['TipoFactura'] ?? null,
                $invoiceData['EstadoFactura'] ?? 1,
                $invoiceData['DirCasaMatriz'] ?? null,
                $invoiceData['DirSucursal'] ?? null,
                $invoiceData['ImporteExcento'] ?? 0,
                $invoiceData['ImporteExento_Desc'] ?? 0,
                $invoiceData['ImporteExento_ISV18'] ?? 0,
                $invoiceData['ImporteExento_ISV15'] ?? 0,
                $invoiceData['ImporteExento_Total'] ?? 0,
                $invoiceData['ImporteExonerado'] ?? 0,
                $invoiceData['ImporteExonerado_Desc'] ?? 0,
                $invoiceData['ImporteExonerado_ISV18'] ?? 0,
                $invoiceData['ImporteExonerado_ISV15'] ?? 0,
                $invoiceData['ImporteExonerado_Total'] ?? 0,
                $invoiceData['ImporteGrabado'] ?? 0,
                $invoiceData['ImporteGravado_Desc'] ?? 0,
                $invoiceData['ImporteGravado_ISV18'] ?? 0,
                $invoiceData['ImporteGravado_ISV15'] ?? 0,
                $invoiceData['ImporteGravado_Total'] ?? 0,
                $invoiceData['DescuentosRebajas'] ?? 0,
                $invoiceData['Isv18'] ?? 0,
                $invoiceData['Isv15'] ?? 0,
                $invoiceData['Total'],
                $now,
                $now,
            ];
        }

        // Todo en una sola transacción — facturas y líneas usan la misma
        // conexión de Laravel, por lo que las FK se satisfacen sin commitear.
        DB::transaction(function () use ($invoiceRows, $chunk, $now) {
            // PASO 1: Insertar facturas con INSERT...RETURNING
            $columnsList = implode(', ', array_map(fn ($c) => "\"{$c}\"", self::INVOICE_COLUMNS));
            $placeholder = '('.implode(', ', array_fill(0, count(self::INVOICE_COLUMNS), '?')).')';
            $bindings = [];
            $valueRows = [];

            foreach ($invoiceRows as $row) {
                $valueRows[] = $placeholder;
                array_push($bindings, ...$row);
            }

            $sql = "INSERT INTO \"invoices\" ({$columnsList}) VALUES "
                 .implode(', ', $valueRows)
                 .' RETURNING "id", "jaremar_id"';

            $inserted = DB::select($sql, $bindings);

            $jarimarToInvoiceId = collect($inserted)->pluck('id', 'jaremar_id')->toArray();

            // PASO 2: Preparar líneas como arrays asociativos e insertar en chunks
            $lineRows = [];
            foreach ($chunk as $invoiceData) {
                $invoiceId = $jarimarToInvoiceId[$invoiceData['Id'] ?? null] ?? null;

                if (! $invoiceId || empty($invoiceData['LineasFactura'])) {
                    continue;
                }

                foreach ($invoiceData['LineasFactura'] as $lineData) {
                    $lineRows[] = [
                        'invoice_id' => $invoiceId,
                        'jaremar_line_id' => $lineData['Id'] ?? null,
                        'invoice_jaremar_id' => isset($lineData['InvoiceId']) ? (int) $lineData['InvoiceId'] : null,
                        'line_number' => (int) ($lineData['NumeroLinea'] ?? 0),
                        'product_id' => (string) ($lineData['ProductoId'] ?? ''),
                        'product_description' => (string) ($lineData['ProductoDesc'] ?? ''),
                        'product_type' => isset($lineData['TipoProducto']) ? (string) $lineData['TipoProducto'] : null,
                        'unit_sale' => isset($lineData['UniVenta']) ? (string) $lineData['UniVenta'] : null,
                        'quantity_fractions' => (float) ($lineData['CantidadFracciones'] ?? 0),
                        'quantity_decimal' => (float) ($lineData['CantidadDecimal'] ?? 0),
                        'quantity_box' => (float) ($lineData['CantidadCaja'] ?? 0),
                        'quantity_min_sale' => (float) ($lineData['CantidadUnidadMinVenta'] ?? 0),
                        'conversion_factor' => (int) ($lineData['FactorConversion'] ?? 1),
                        'cost' => (float) ($lineData['Costo'] ?? 0),
                        'price' => (float) ($lineData['Precio'] ?? 0),
                        'price_min_sale' => (float) ($lineData['PrecioUnidadMinVenta'] ?? 0),
                        'subtotal' => (float) ($lineData['Subtotal'] ?? 0),
                        'discount' => (float) ($lineData['Descuento'] ?? 0),
                        'discount_percent' => (float) ($lineData['PorcentajeDescuento'] ?? 0),
                        'tax' => (float) ($lineData['Impuesto'] ?? 0),
                        'tax_percent' => (float) ($lineData['PorcentajeImpuesto'] ?? 0),
                        'tax18' => (float) ($lineData['Impuesto18'] ?? 0),
                        'total' => (float) ($lineData['Total'] ?? 0),
                        'weight' => (float) ($lineData['Peso'] ?? 0),
                        'volume' => (float) ($lineData['Volumen'] ?? 0),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // Insertar líneas en chunks de 500 vía DB facade (misma conexión = FK OK)
            foreach (array_chunk($lineRows, self::LINE_CHUNK_SIZE) as $lineChunk) {
                DB::table('invoice_lines')->insert($lineChunk);
            }
        });
    }

    // ─── Helpers ──────────────────────────────────────────────

    protected function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($date)->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function getUnknownWarehouses(): array
    {
        return $this->unknownWarehouses;
    }

    public function hasUnknownWarehouses(): bool
    {
        return ! empty($this->unknownWarehouses);
    }
}
