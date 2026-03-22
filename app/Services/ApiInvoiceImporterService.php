<?php

namespace App\Services;

use App\Models\ApiInvoiceImport;
use App\Models\ApiInvoiceImportConflict;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApiInvoiceImporterService
{
    /** Cache de bodegas: ['OAC' => 1, 'OAO' => 2, 'OAS' => 3] */
    protected array $warehouseMap = [];

    /**
     * Campos que se comparan para detectar cambios.
     * No incluimos campos de auditoría ni IDs internos.
     */
    protected array $comparableFields = [
        'total', 'isv15', 'isv18', 'discounts',
        'importe_gravado', 'importe_gravado_isv15', 'importe_gravado_total',
        'importe_exento_total', 'importe_exonerado_total',
        'client_name', 'client_rtn', 'deliver_to',
        'seller_id', 'seller_name',
        'payment_type', 'credit_days',
        'invoice_date', 'due_date',
        'route_number', 'warehouse_id',
    ];

    public function __construct()
    {
        $this->warehouseMap = Warehouse::pluck('id', 'code')->toArray();
    }

    /**
     * Procesa un batch de facturas recibido por API.
     *
     * La validación de fechas vive en el controller ANTES del hash detector.
     * Aquí solo procesamos almacenes y lógica de negocio.
     *
     * @return array Resumen del procesamiento
     */
    public function processBatch(array $invoices, ApiInvoiceImport $importRecord): array
    {
        $summary = [
            'invoices_inserted'       => 0,
            'invoices_updated'        => 0,
            'invoices_unchanged'      => 0,
            'invoices_pending_review' => 0,
            'invoices_rejected'       => 0,
            'manifiestos_rechazados'  => [],
            'warnings'                => [],
            'errors'                  => [],
        ];

        $grouped = collect($invoices)->groupBy('NumeroManifiesto');

        foreach ($grouped as $manifestNumber => $manifestInvoices) {
            $this->processManifestGroup(
                (string) $manifestNumber,
                $manifestInvoices->values()->all(),
                $importRecord,
                $summary
            );
        }

        return $summary;
    }

    /**
     * Valida fechas de manifiestos existentes para el controller.
     *
     * Separa el batch en dos grupos:
     *   - manifiestos_invalidos: existen en BD y fueron creados antes de hoy
     *   - manifiestos_validos:   son nuevos O fueron creados hoy
     *
     * Si hay aunque sea UN manifiesto inválido → rechazar TODO el batch.
     * Jaremar recibe detalle completo de qué rechazar y qué reenviar solo.
     *
     * @return array {
     *   'tiene_errores'         => bool,
     *   'manifiestos_invalidos' => [...],
     *   'manifiestos_validos'   => [...],
     * }
     */
    public function validateManifestDatesForController(array $manifestNumbers, array $invoices): array
    {
        $today    = now()->toDateString();
        $invalidos = [];
        $validos   = [];

        // Solo validamos manifiestos que YA EXISTEN en BD.
        $existing = Manifest::whereIn('number', $manifestNumbers)
            ->select('number', 'date', 'created_at')
            ->get()
            ->keyBy('number');

        // Agrupar facturas por manifiesto para listarlas en el error/info
        $facturasPorManifiesto = collect($invoices)
            ->groupBy('NumeroManifiesto')
            ->map(fn ($group) => $group->pluck('Nfactura')->toArray());

        foreach ($manifestNumbers as $number) {
            $facturasDelManifiesto = $facturasPorManifiesto[$number] ?? [];

            // Manifiesto nuevo — siempre válido
            if (!isset($existing[$number])) {
                $validos[] = [
                    'manifiesto'   => $number,
                    'tipo'         => 'nuevo',
                    'total_facturas' => count($facturasDelManifiesto),
                    'facturas'     => $facturasDelManifiesto,
                    'nota'         => "Manifiesto nuevo — será creado al procesar el batch.",
                ];
                continue;
            }

            $manifest    = $existing[$number];
            $createdDate = $manifest->created_at->toDateString();

            // Manifiesto de hoy — válido
            if ($createdDate === $today) {
                $validos[] = [
                    'manifiesto'     => $number,
                    'tipo'           => 'existente_hoy',
                    'total_facturas' => count($facturasDelManifiesto),
                    'facturas'       => $facturasDelManifiesto,
                    'nota'           => "Manifiesto existente del día de hoy — se procesará normalmente.",
                ];
                continue;
            }

            // Manifiesto de día anterior — inválido
            $invalidos[] = [
                'manifiesto'         => $number,
                'fecha_original'     => $createdDate,
                'fecha_intento'      => $today,
                'total_facturas'     => count($facturasDelManifiesto),
                'facturas_afectadas' => $facturasDelManifiesto,
                'instruccion'        => "El manifiesto #{$number} fue creado el {$createdDate} y ya no acepta facturas nuevas. Reenvíe estas facturas en un nuevo número de manifiesto.",
            ];
        }

        return [
            'tiene_errores'         => !empty($invalidos),
            'manifiestos_invalidos' => $invalidos,
            'manifiestos_validos'   => $validos,
        ];
    }

    protected function processManifestGroup(
        string $manifestNumber,
        array $invoices,
        ApiInvoiceImport $importRecord,
        array &$summary
    ): void {
        // ── 1. Validar almacenes ANTES de tocar la BD ─────────────────
        $warehouseErrors = $this->validateWarehouses($invoices);

        if (!empty($warehouseErrors)) {
            $totalFacturas = count($invoices);
            $summary['invoices_rejected'] += $totalFacturas;
            $summary['manifiestos_rechazados'][] = [
                'manifiesto'             => $manifestNumber,
                'total_facturas'         => $totalFacturas,
                'almacenes_desconocidos' => $warehouseErrors,
            ];

            Log::warning("API Jaremar: manifiesto #{$manifestNumber} rechazado por almacenes desconocidos.", [
                'almacenes_desconocidos' => array_keys($warehouseErrors),
                'total_facturas'         => $totalFacturas,
            ]);

            return;
        }

        // ── 2. Manifiesto cerrado → rechazar todas sus facturas ────────
        $manifest = Manifest::where('number', $manifestNumber)->first();

        if ($manifest && $manifest->isClosed()) {
            foreach ($invoices as $invoice) {
                $summary['invoices_rejected']++;
                $summary['errors'][] = [
                    'factura'    => $invoice['Nfactura'],
                    'manifiesto' => $manifestNumber,
                    'motivo'     => "El manifiesto #{$manifestNumber} está cerrado y no acepta modificaciones.",
                ];
            }
            return;
        }

        // ── 3. Manifiesto no existe → crearlo ─────────────────────────
        if (!$manifest) {
            $manifest = $this->createManifest($manifestNumber);
        }

        // ── 4. Procesar cada factura individualmente ───────────────────
        foreach ($invoices as $invoiceData) {
            $this->processInvoice($invoiceData, $manifest, $importRecord, $summary);
        }

        // ── 5. Recalcular totales del manifiesto ───────────────────────
        $manifest->recalculateTotals();
    }

    /**
     * Valida que todos los almacenes del batch existan en el sistema.
     */
    protected function validateWarehouses(array $invoices): array
    {
        $errors = [];

        foreach ($invoices as $invoice) {
            $code = $invoice['Almacen'] ?? null;

            if (empty($code)) {
                $errors['(vacío)'][] = $invoice['Nfactura'];
                continue;
            }

            if (!isset($this->warehouseMap[$code])) {
                $errors[$code][] = $invoice['Nfactura'];
            }
        }

        return $errors;
    }

    protected function processInvoice(
        array $invoiceData,
        Manifest $manifest,
        ApiInvoiceImport $importRecord,
        array &$summary
    ): void {
        $invoiceNumber = $invoiceData['Nfactura'];
        $existing      = Invoice::where('invoice_number', $invoiceNumber)->first();

        // Factura existe en otro manifiesto → rechazar
        if ($existing && $existing->manifest_id !== $manifest->id) {
            $summary['invoices_rejected']++;
            $summary['errors'][] = [
                'factura'    => $invoiceNumber,
                'manifiesto' => $manifest->number,
                'motivo'     => "La factura {$invoiceNumber} ya existe en el manifiesto #{$existing->manifest->number} y no puede duplicarse.",
            ];
            return;
        }

        // Factura nueva → insertar
        if (!$existing) {
            $invoice = $this->insertInvoice($invoiceData, $manifest);
            $summary['invoices_inserted']++;

            activity('api')
                ->performedOn($invoice)
                ->withProperties([
                    'batch_uuid' => $importRecord->batch_uuid,
                    'source'     => 'jaremar_api',
                ])
                ->log("Factura #{$invoiceNumber} insertada via API en manifiesto #{$manifest->number}.");
            return;
        }

        // Factura existente → comparar campos
        $incomingMapped = $this->mapInvoiceFields($invoiceData, $manifest);
        $changes        = $this->detectChanges($existing, $incomingMapped);

        if (empty($changes)) {
            $summary['invoices_unchanged']++;
            return;
        }

        ApiInvoiceImportConflict::create([
            'api_invoice_import_id' => $importRecord->id,
            'invoice_id'            => $existing->id,
            'invoice_number'        => $invoiceNumber,
            'manifest_number'       => $manifest->number,
            'previous_values'       => $changes['previous'],
            'incoming_values'       => $changes['incoming'],
        ]);

        activity('api')
            ->performedOn($existing)
            ->withProperties([
                'batch_uuid'         => $importRecord->batch_uuid,
                'campos_modificados' => array_keys($changes['previous']),
                'source'             => 'jaremar_api',
            ])
            ->log("Conflicto detectado en factura #{$invoiceNumber} — datos diferentes a los existentes.");

        $summary['invoices_pending_review']++;
        $summary['warnings'][] = [
            'factura'           => $invoiceNumber,
            'manifiesto'        => $manifest->number,
            'campos_con_cambio' => array_keys($changes['previous']),
            'mensaje'           => 'Factura recibida con diferencias respecto a la versión existente. Pendiente de revisión por Hosana.',
        ];
    }

    protected function detectChanges(Invoice $existing, array $incoming): array
    {
        $previous  = [];
        $newValues = [];

        foreach ($this->comparableFields as $field) {
            if (!array_key_exists($field, $incoming)) {
                continue;
            }

            $existingValue = $existing->getRawOriginal($field);
            $incomingValue = $incoming[$field];

            $existingNorm = is_numeric($existingValue) ? (float) $existingValue : (string) $existingValue;
            $incomingNorm = is_numeric($incomingValue) ? (float) $incomingValue : (string) $incomingValue;

            if ($existingNorm !== $incomingNorm) {
                $previous[$field]  = $existingValue;
                $newValues[$field] = $incomingValue;
            }
        }

        if (empty($previous)) {
            return [];
        }

        return [
            'previous' => $previous,
            'incoming' => $newValues,
        ];
    }

    protected function insertInvoice(array $data, Manifest $manifest): Invoice
    {
        $mapped                = $this->mapInvoiceFields($data, $manifest);
        $mapped['manifest_id'] = $manifest->id;

        $invoice = Invoice::create($mapped);

        if (!empty($data['LineasFactura'])) {
            $this->insertLines($invoice->id, $data['LineasFactura']);
        }

        return $invoice;
    }

    protected function insertLines(int $invoiceId, array $lines): void
    {
        $now  = now()->toDateTimeString();
        $rows = [];

        foreach ($lines as $line) {
            $cantidadFracciones = (float) ($line['CantidadFracciones'] ?? 0);
            $cantidadCaja       = (float) ($line['CantidadCaja'] ?? 0);
            $factorConversion   = max(1, (int) ($line['FactorConversion'] ?? 1));

            // Para productos CJ (caja): Jaremar envía CantidadFracciones=0 y CantidadCaja>0.
            // quantity_fractions debe reflejar el total de fracciones disponibles para devolución:
            //   CantidadCaja * FactorConversion  (ej: 1 caja × 12 = 12 fracciones)
            // Si ya vienen fracciones sueltas, se respetan tal cual.
            if ($cantidadFracciones == 0 && $cantidadCaja > 0) {
                $cantidadFracciones = $cantidadCaja * $factorConversion;
            }

            $rows[] = [
                'invoice_id'          => $invoiceId,
                'jaremar_line_id'     => $line['Id'] ?? null,
                'invoice_jaremar_id'  => isset($line['InvoiceId']) ? (int) $line['InvoiceId'] : null,
                'line_number'         => (int) ($line['NumeroLinea'] ?? 0),
                'product_id'          => (string) ($line['ProductoId'] ?? ''),
                'product_description' => (string) ($line['ProductoDesc'] ?? ''),
                'product_type'        => $line['TipoProducto'] ?? null,
                'unit_sale'           => $line['UniVenta'] ?? null,
                'quantity_fractions'  => $cantidadFracciones,
                'quantity_decimal'    => (float) ($line['CantidadDecimal'] ?? 0),
                'quantity_box'        => $cantidadCaja,
                'quantity_min_sale'   => (float) ($line['CantidadUnidadMinVenta'] ?? 0),
                'conversion_factor'   => $factorConversion,
                'cost'                => (float) ($line['Costo'] ?? 0),
                'price'               => (float) ($line['Precio'] ?? 0),
                'price_min_sale'      => (float) ($line['PrecioUnidadMinVenta'] ?? 0),
                'subtotal'            => (float) ($line['Subtotal'] ?? 0),
                'discount'            => (float) ($line['Descuento'] ?? 0),
                'discount_percent'    => (float) ($line['PorcentajeDescuento'] ?? 0),
                'tax'                 => (float) ($line['Impuesto'] ?? 0),
                'tax_percent'         => (float) ($line['PorcentajeImpuesto'] ?? 0),
                'tax18'               => (float) ($line['Impuesto18'] ?? 0),
                'total'               => (float) ($line['Total'] ?? 0),
                'weight'              => (float) ($line['Peso'] ?? 0),
                'volume'              => (float) ($line['Volumen'] ?? 0),
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }

        collect($rows)->chunk(500)->each(function ($chunk) {
            DB::table('invoice_lines')->insert($chunk->values()->all());
        });
    }

    protected function mapInvoiceFields(array $data, Manifest $manifest): array
    {
        $warehouseCode = $data['Almacen'] ?? null;
        $warehouseId   = $this->warehouseMap[$warehouseCode] ?? null;

        return [
            'warehouse_id'             => $warehouseId,
            'status'                   => $warehouseId ? 'imported' : 'pending_warehouse',
            'jaremar_id'               => $data['Id'] ?? null,
            'invoice_number'           => $data['Nfactura'],
            'lx_number'                => $data['NumeroFacturaLX'] ?? null,
            'order_number'             => $data['NumeroPedido'] ?? null,
            'invoice_date'             => $this->parseDate($data['FechaFactura']),
            'due_date'                 => $this->parseDate($data['FechaVencimiento'] ?? null),
            'print_limit_date'         => $this->parseDate($data['FechaLimImpre'] ?? null),
            'seller_id'                => $data['Vendedorid'] ?? null,
            'seller_name'              => $data['Vendedor'] ?? null,
            'client_id'                => $data['Clienteid'] ?? null,
            'client_name'              => $data['Cliente'],
            'client_rtn'               => $data['Rtn'] ?? null,
            'deliver_to'               => $data['EntregarA'] ?? null,
            'department'               => $data['Depto'] ?? null,
            'municipality'             => $data['Municipio'] ?? null,
            'neighborhood'             => $data['Barrio'] ?? null,
            'address'                  => $data['Direccion'] ?? null,
            'phone'                    => $data['Tel'] ?? null,
            'longitude'                => $data['Longitud'] ?? null,
            'latitude'                 => $data['Latitud'] ?? null,
            'route_number'             => trim($data['NumeroRuta'] ?? ''),
            'cai'                      => $data['Cai'] ?? null,
            'range_start'              => $data['Rinicial'] ?? null,
            'range_end'                => $data['Rfinal'] ?? null,
            'payment_type'             => $data['TipoPago'] ?? null,
            'credit_days'              => $data['DiasCred'] ?? 0,
            'invoice_type'             => $data['TipoFactura'] ?? null,
            'invoice_status'           => $data['EstadoFactura'] ?? 1,
            'matriz_address'           => $data['DirCasaMatriz'] ?? null,
            'branch_address'           => $data['DirSucursal'] ?? null,
            'importe_excento'          => $data['ImporteExcento'] ?? 0,
            'importe_exento_desc'      => $data['ImporteExento_Desc'] ?? 0,
            'importe_exento_isv18'     => $data['ImporteExento_ISV18'] ?? 0,
            'importe_exento_isv15'     => $data['ImporteExento_ISV15'] ?? 0,
            'importe_exento_total'     => $data['ImporteExento_Total'] ?? 0,
            'importe_exonerado'        => $data['ImporteExonerado'] ?? 0,
            'importe_exonerado_desc'   => $data['ImporteExonerado_Desc'] ?? 0,
            'importe_exonerado_isv18'  => $data['ImporteExonerado_ISV18'] ?? 0,
            'importe_exonerado_isv15'  => $data['ImporteExonerado_ISV15'] ?? 0,
            'importe_exonerado_total'  => $data['ImporteExonerado_Total'] ?? 0,
            'importe_gravado'          => $data['ImporteGrabado'] ?? 0,
            'importe_gravado_desc'     => $data['ImporteGravado_Desc'] ?? 0,
            'importe_gravado_isv18'    => $data['ImporteGravado_ISV18'] ?? 0,
            'importe_gravado_isv15'    => $data['ImporteGravado_ISV15'] ?? 0,
            'importe_gravado_total'    => $data['ImporteGravado_Total'] ?? 0,
            'discounts'                => $data['DescuentosRebajas'] ?? 0,
            'isv18'                    => $data['Isv18'] ?? 0,
            'isv15'                    => $data['Isv15'] ?? 0,
            'total'                    => $data['Total'],
        ];
    }

    protected function createManifest(string $manifestNumber): Manifest
    {
        $supplier = Supplier::where('is_active', true)->first()
            ?? throw new \RuntimeException(
                'No se encontró ningún proveedor activo en el sistema. ' .
                'Configure al menos un proveedor activo antes de importar facturas vía API.'
            );

        $manifest = Manifest::create([
            'supplier_id'  => $supplier->id,
            'warehouse_id' => null,
            'number'       => $manifestNumber,
            'date'         => now()->toDateString(),
            'status'       => 'imported',
            'created_by'   => null,
            'updated_by'   => null,
        ]);

        activity('api')
            ->performedOn($manifest)
            ->withProperties(['source' => 'jaremar_api'])
            ->log("Manifiesto #{$manifestNumber} creado via API de Jaremar.");

        return $manifest;
    }

    /**
     * Parsea una fecha de Jaremar en cualquiera de sus formatos conocidos.
     *
     * Formatos soportados:
     *   "dd/mm/yyyy"                   → "20/03/2026"               (Postman / pruebas)
     *   "yyyy-mm-ddTHH:mm:ss.mssZ"     → "2025-12-28T00:00:00.000Z" (API real Jaremar)
     *   "yyyy-mm-ddTHH:mm:ssZ"         → "2025-12-28T00:00:00Z"
     *   "yyyy-mm-ddTHH:mm:ss"          → "2025-12-28T00:00:00"
     *   "yyyy-mm-dd"                   → "2025-12-28"
     *
     * Regla de oro:
     *   - Solo "dd/mm/yyyy" necesita tratamiento especial: PHP trata "/" como
     *     separador m/d/Y (americano), así que mes=20 es inválido → null.
     *   - Todos los formatos ISO 8601 (incluido el "Z" de UTC y milisegundos)
     *     los maneja Carbon::parse() de forma nativa y correcta.
     *
     * @return string|null  Fecha en formato Y-m-d, o null si no se pudo parsear.
     */
    protected function parseDate(?string $date): ?string
    {
        if (!$date) return null;

        try {
            // Caso especial: formato latinoamericano dd/mm/yyyy (ej: "20/03/2026").
            // Carbon::parse() lo leería como m/d/Y → mes=20 inválido.
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
                return Carbon::createFromFormat('d/m/Y', $date)->toDateString();
            }

            // Todos los demás formatos (ISO 8601, SQL, timestamps, etc.)
            // Carbon::parse() los resuelve correctamente, incluyendo:
            //   "2025-12-28T00:00:00.000Z"  → UTC con milisegundos
            //   "2025-12-28T00:00:00Z"      → UTC sin milisegundos
            //   "2025-12-28T00:00:00"       → ISO sin zona
            //   "2025-12-28"                → SQL estándar
            return Carbon::parse($date)->toDateString();
        } catch (\Exception $e) {
            return null;
        }
    }
}