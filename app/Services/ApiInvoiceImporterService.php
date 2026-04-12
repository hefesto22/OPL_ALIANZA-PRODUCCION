<?php

namespace App\Services;

use App\Models\ApiInvoiceImport;
use App\Models\ApiInvoiceImportConflict;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\InvoicesImported;
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
     * Optimización clave: todas las facturas existentes se precargan en UNA
     * sola query al inicio (whereIn), eliminando el problema N+1 que hacía
     * un SELECT por factura del batch dentro de processInvoice().
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
            'inserted_warehouse_counts' => [],
        ];

        // ── Preload: UNA sola query para resolver todas las existencias ───
        // Antes: processInvoice() hacía Invoice::where(invoice_number)->first()
        // por cada factura del batch (N queries). Para batches de miles de
        // facturas esto dominaba el tiempo de respuesta.
        $allNumbers = collect($invoices)
            ->pluck('Nfactura')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $existingInvoices = !empty($allNumbers)
            ? Invoice::with('manifest:id,number')
                ->whereIn('invoice_number', $allNumbers)
                ->get()
                ->keyBy('invoice_number')
            : collect();

        $grouped = collect($invoices)->groupBy('NumeroManifiesto');

        foreach ($grouped as $manifestNumber => $manifestInvoices) {
            $this->processManifestGroup(
                (string) $manifestNumber,
                $manifestInvoices->values()->all(),
                $importRecord,
                $summary,
                $existingInvoices,
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
        array &$summary,
        \Illuminate\Support\Collection $existingInvoices,
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

        // ── 4. Clasificar facturas: nuevas, sin cambios, o con conflicto
        $classified = $this->classifyInvoices($invoices, $manifest, $existingInvoices, $summary);

        // ── 5. Insert masivo de facturas nuevas + sus líneas ───────────
        // Aislar conteos de bodegas para ESTE manifiesto antes del bulk.
        $prevWarehouseCounts = $summary['inserted_warehouse_counts'];
        $summary['inserted_warehouse_counts'] = [];

        if (!empty($classified['new'])) {
            $bulkResult = $this->bulkInsertNewInvoices($classified['new'], $manifest);
            $summary['invoices_inserted']        += $bulkResult['total'];
            $summary['inserted_warehouse_counts'] = $bulkResult['by_warehouse'];
        }

        // ── 6. Crear filas de conflicto ────────────────────────────────
        $this->createConflictRows($classified['conflicts'], $importRecord, $manifest, $summary);

        // Restaurar acumulado global de conteos por bodega
        $thisManifestWarehouseCounts = $summary['inserted_warehouse_counts'];
        foreach ($thisManifestWarehouseCounts as $whId => $count) {
            $prevWarehouseCounts[$whId] = ($prevWarehouseCounts[$whId] ?? 0) + $count;
        }
        $summary['inserted_warehouse_counts'] = $prevWarehouseCounts;

        // ── 7. Recalcular totales del manifiesto ───────────────────────
        $manifest->recalculateTotals();

        // ── 8. Log + notificaciones ───────────────────────────────────
        $this->logManifestImport($manifest, $manifestNumber, $importRecord, $summary, count($invoices));

        if (!empty($thisManifestWarehouseCounts)) {
            $this->notifyWarehouseUsers($manifest, $thisManifestWarehouseCounts);
        }
    }

    /**
     * Clasifica las facturas del batch en 3 categorías:
     *   - new:       facturas que no existen → se insertarán en bulk
     *   - conflicts: facturas existentes con campos distintos → pendientes de revisión
     *   - (unchanged e invoices en otro manifiesto se registran directo en $summary)
     *
     * @return array{new: array, conflicts: array}
     */
    protected function classifyInvoices(
        array $invoices,
        Manifest $manifest,
        \Illuminate\Support\Collection $existingInvoices,
        array &$summary,
    ): array {
        $newInvoicesData   = [];
        $conflictsToCreate = [];

        foreach ($invoices as $invoiceData) {
            $invoiceNumber = $invoiceData['Nfactura'];
            $existing      = $existingInvoices->get($invoiceNumber);

            // Factura existe en otro manifiesto → rechazar
            if ($existing && $existing->manifest_id !== $manifest->id) {
                $summary['invoices_rejected']++;
                $summary['errors'][] = [
                    'factura'    => $invoiceNumber,
                    'manifiesto' => $manifest->number,
                    'motivo'     => "La factura {$invoiceNumber} ya existe en el manifiesto #{$existing->manifest->number} y no puede duplicarse.",
                ];
                continue;
            }

            // Factura nueva → se insertará en bulk
            if (!$existing) {
                $newInvoicesData[] = $invoiceData;
                continue;
            }

            // Factura existente en el mismo manifiesto → comparar campos
            $incomingMapped = $this->mapInvoiceFields($invoiceData, $manifest);
            $changes        = $this->detectChanges($existing, $incomingMapped);

            if (empty($changes)) {
                $summary['invoices_unchanged']++;
                continue;
            }

            $conflictsToCreate[] = [
                'existing'       => $existing,
                'changes'        => $changes,
                'invoice_number' => $invoiceNumber,
            ];
        }

        return ['new' => $newInvoicesData, 'conflicts' => $conflictsToCreate];
    }

    /**
     * Persiste las filas de conflicto y actualiza el summary con warnings.
     */
    protected function createConflictRows(
        array $conflicts,
        ApiInvoiceImport $importRecord,
        Manifest $manifest,
        array &$summary,
    ): void {
        foreach ($conflicts as $conflict) {
            ApiInvoiceImportConflict::create([
                'api_invoice_import_id' => $importRecord->id,
                'invoice_id'            => $conflict['existing']->id,
                'invoice_number'        => $conflict['invoice_number'],
                'manifest_number'       => $manifest->number,
                'previous_values'       => $conflict['changes']['previous'],
                'incoming_values'       => $conflict['changes']['incoming'],
            ]);

            $summary['invoices_pending_review']++;
            $summary['warnings'][] = [
                'factura'           => $conflict['invoice_number'],
                'manifiesto'        => $manifest->number,
                'campos_con_cambio' => array_keys($conflict['changes']['previous']),
                'mensaje'           => 'Factura recibida con diferencias respecto a la versión existente. Pendiente de revisión por Hosana.',
            ];
        }
    }

    /**
     * Registra un activity log consolidado por manifiesto.
     */
    protected function logManifestImport(
        Manifest $manifest,
        string $manifestNumber,
        ApiInvoiceImport $importRecord,
        array $summary,
        int $totalProcessed,
    ): void {
        $parts = [];
        if ($summary['invoices_inserted'] > 0) {
            $parts[] = "{$summary['invoices_inserted']} insertadas";
        }
        if ($summary['invoices_unchanged'] > 0) {
            $parts[] = "{$summary['invoices_unchanged']} sin cambios";
        }
        if ($summary['invoices_pending_review'] > 0) {
            $parts[] = "{$summary['invoices_pending_review']} con conflictos";
        }
        if ($summary['invoices_rejected'] > 0) {
            $parts[] = "{$summary['invoices_rejected']} rechazadas";
        }

        $description = "Manifiesto #{$manifestNumber} importado via API: {$totalProcessed} facturas procesadas";
        if (!empty($parts)) {
            $description .= ' (' . implode(', ', $parts) . ')';
        }

        activity('api')
            ->performedOn($manifest)
            ->withProperties([
                'batch_uuid'            => $importRecord->batch_uuid,
                'source'                => 'jaremar_api',
                'invoices_total'        => $totalProcessed,
                'invoices_inserted'     => $summary['invoices_inserted'],
                'invoices_unchanged'    => $summary['invoices_unchanged'],
                'invoices_pending'      => $summary['invoices_pending_review'],
                'invoices_rejected'     => $summary['invoices_rejected'],
            ])
            ->log($description);
    }

    /**
     * Envía notificaciones a usuarios de bodegas que recibieron facturas nuevas.
     *
     * - Usuarios de bodega: reciben notificación solo de SU bodega.
     * - Usuarios globales (admin, super_admin, finance): reciben resumen de TODAS las bodegas.
     *
     * @param array<int, int> $warehouseCounts  [warehouse_id => cantidad_insertada]
     */
    protected function notifyWarehouseUsers(Manifest $manifest, array $warehouseCounts): void
    {
        if (empty($warehouseCounts)) {
            return;
        }

        try {
            $affectedIds    = array_keys($warehouseCounts);
            $warehouseNames = Warehouse::whereIn('id', $affectedIds)->pluck('name', 'id');
            $totalInserted  = array_sum($warehouseCounts);
            $manifestUrl    = '/admin/manifests/' . $manifest->id;
            $notified       = 0;

            // ── Notificar usuarios de cada bodega afectada ───────────
            $warehouseUsers = User::whereIn('warehouse_id', $affectedIds)
                ->where('is_active', true)
                ->get();

            foreach ($warehouseUsers as $user) {
                $count = $warehouseCounts[$user->warehouse_id] ?? 0;
                if ($count === 0) continue;

                $warehouseName = $warehouseNames[$user->warehouse_id] ?? 'Desconocida';

                $user->notify(new InvoicesImported(
                    title: 'Nuevas facturas importadas',
                    body: "{$count} facturas importadas al manifiesto #{$manifest->number} para {$warehouseName}.",
                    actionUrl: $manifestUrl,
                    iconColor: 'success',
                ));
                $notified++;
            }

            // ── Notificar usuarios globales (sin bodega) con resumen ─
            $globalUsers = User::whereNull('warehouse_id')
                ->where('is_active', true)
                ->get();

            if ($globalUsers->isNotEmpty()) {
                $breakdown = collect($warehouseCounts)
                    ->map(fn (int $count, int $id) => ($warehouseNames[$id] ?? '?') . ": {$count}")
                    ->implode(', ');

                foreach ($globalUsers as $user) {
                    $user->notify(new InvoicesImported(
                        title: "Importación API: Manifiesto #{$manifest->number}",
                        body: "{$totalInserted} facturas importadas ({$breakdown}).",
                        actionUrl: $manifestUrl,
                        iconColor: 'info',
                    ));
                    $notified++;
                }
            }

            Log::info("API Notificaciones: {$notified} notificaciones enviadas para manifiesto #{$manifest->number}.", [
                'warehouse_users' => $warehouseUsers->count(),
                'global_users'    => $globalUsers->count(),
                'warehouses'      => $warehouseCounts,
            ]);

        } catch (\Throwable $e) {
            // No interrumpir el flujo de importación si falla la notificación
            Log::error('API Notificaciones: error al enviar.', [
                'manifest' => $manifest->number,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
        }
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

    /**
     * Inserta en bulk todas las facturas nuevas de un manifiesto y sus
     * líneas asociadas. Usa INSERT ... RETURNING para recuperar los IDs
     * generados y poder asociar las líneas sin queries extra.
     *
     * @param  array<int, array>  $newInvoicesData  Payload crudo de Jaremar
     * @return array{total:int, by_warehouse:array<int,int>}
     */
    protected function bulkInsertNewInvoices(array $newInvoicesData, Manifest $manifest): array
    {
        $now    = now()->toDateTimeString();
        $counts = ['total' => 0, 'by_warehouse' => []];

        // ── 1. Preparar filas mapeadas ──────────────────────────────────
        $rows = [];
        foreach ($newInvoicesData as $data) {
            $mapped = $this->mapInvoiceFields($data, $manifest);
            $mapped['manifest_id'] = $manifest->id;
            $mapped['created_at']  = $now;
            $mapped['updated_at']  = $now;

            $rows[] = $mapped;
            $counts['total']++;

            if (!empty($mapped['warehouse_id'])) {
                $counts['by_warehouse'][$mapped['warehouse_id']] =
                    ($counts['by_warehouse'][$mapped['warehouse_id']] ?? 0) + 1;
            }
        }

        if (empty($rows)) {
            return $counts;
        }

        // ── 2. INSERT masivo con RETURNING para recuperar los IDs ───────
        // Se divide en chunks de 500 para mantener el número de bindings
        // muy por debajo del límite de PostgreSQL (~65,535 placeholders).
        $invoiceNumberToId = [];

        DB::transaction(function () use ($rows, &$invoiceNumberToId) {
            foreach (array_chunk($rows, 500) as $chunk) {
                $columns     = array_keys($chunk[0]);
                $columnList  = implode(', ', array_map(fn ($c) => "\"{$c}\"", $columns));
                $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

                $valueRows = [];
                $bindings  = [];
                foreach ($chunk as $row) {
                    $valueRows[] = $placeholder;
                    foreach ($columns as $c) {
                        $bindings[] = $row[$c];
                    }
                }

                $sql = "INSERT INTO \"invoices\" ({$columnList}) VALUES "
                     . implode(', ', $valueRows)
                     . " RETURNING \"id\", \"invoice_number\"";

                $inserted = DB::select($sql, $bindings);

                foreach ($inserted as $r) {
                    $invoiceNumberToId[$r->invoice_number] = (int) $r->id;
                }
            }
        });

        // ── 3. Preparar e insertar líneas en bulk ───────────────────────
        $allLines = [];
        foreach ($newInvoicesData as $data) {
            $invoiceId = $invoiceNumberToId[$data['Nfactura']] ?? null;
            if (!$invoiceId || empty($data['LineasFactura'])) {
                continue;
            }

            foreach ($data['LineasFactura'] as $line) {
                $cantidadFracciones = (float) ($line['CantidadFracciones'] ?? 0);
                $cantidadCaja       = (float) ($line['CantidadCaja'] ?? 0);
                $factorConversion   = max(1, (int) ($line['FactorConversion'] ?? 1));

                // Para productos CJ (caja): Jaremar envía CantidadFracciones=0
                // y CantidadCaja>0. quantity_fractions debe reflejar el total
                // de fracciones disponibles para devolución:
                //   CantidadCaja * FactorConversion
                if ($cantidadFracciones == 0 && $cantidadCaja > 0) {
                    $cantidadFracciones = $cantidadCaja * $factorConversion;
                }

                $allLines[] = [
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
        }

        if (!empty($allLines)) {
            collect($allLines)->chunk(500)->each(function ($chunk) {
                DB::table('invoice_lines')->insert($chunk->values()->all());
            });
        }

        return $counts;
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