<?php

namespace App\Services;

use App\Models\ApiInvoiceImport;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\InvoicesImported;
use App\Support\BoxEquivalence;
use App\Support\InvoiceFingerprint;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApiInvoiceImporterService
{
    /** Cache de bodegas: ['OAC' => 1, 'OAO' => 2, 'OAS' => 3] */
    protected array $warehouseMap = [];

    protected ManifestDateValidator $dateValidator;

    public function __construct(?ManifestDateValidator $dateValidator = null)
    {
        $this->warehouseMap = Warehouse::pluck('id', 'code')->toArray();

        // Argumento opcional con default-construido para mantener compatibilidad
        // con cualquier resolve() existente que instancie el service sin args.
        // Laravel inyecta el servicio cuando se resuelve del container — en
        // tests se puede pasar uno mockeado vía constructor explícito.
        $this->dateValidator = $dateValidator ?? new ManifestDateValidator;
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
            'invoices_inserted' => 0,
            'invoices_updated' => 0,
            'invoices_unchanged' => 0,
            'invoices_pending_review' => 0,
            'invoices_rejected' => 0,
            'manifiestos_rechazados' => [],
            'warnings' => [],
            'errors' => [],
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

        $existingInvoices = ! empty($allNumbers)
            ? Invoice::with('manifest:id,number')
                ->whereIn('invoice_number', $allNumbers)
                ->get()
                ->keyBy('invoice_number')
            : collect();

        $grouped = collect($invoices)->groupBy('NumeroManifiesto');

        // ── FASE 1: ANALIZAR todo el lote SIN escribir en BD ───────────────
        // Estricto total (todo o nada): detectamos cualquier anomalía. Si
        // UNA sola falla, no se inserta nada del lote.
        $plan = [];
        $rechazos = [];

        foreach ($grouped as $manifestNumber => $manifestInvoices) {
            $analysis = $this->analyzeManifestGroup(
                (string) $manifestNumber,
                $manifestInvoices->values()->all(),
                $existingInvoices,
            );

            if ($analysis['rejected']) {
                $rechazos[] = $analysis['rejection'];
            } else {
                $plan[] = $analysis['plan'];
            }
        }

        // ── Decisión atómica: cualquier rechazo → NO se inserta nada ───────
        if (! empty($rechazos)) {
            $summary['manifiestos_rechazados'] = $rechazos;
            $summary['invoices_rejected'] = count($invoices);

            Log::warning('API Jaremar: lote rechazado completo (todo o nada).', [
                'batch_uuid' => $importRecord->batch_uuid,
                'total_facturas' => count($invoices),
                'manifiestos_rechazados' => count($rechazos),
            ]);

            return $summary;
        }

        // ── FASE 2: APLICAR todo el lote en UNA transacción ────────────────
        // O entra todo, o no entra nada. Las notificaciones se disparan
        // DESPUÉS del commit para no bloquear la transacción con IO.
        $notifyQueue = [];
        $duplicateNotifyQueue = [];

        DB::transaction(function () use ($plan, $importRecord, &$summary, &$notifyQueue, &$duplicateNotifyQueue) {
            foreach ($plan as $group) {
                $manifest = $group['manifest']
                    ?? $this->createManifest($group['manifest_number'], $group['operation_date']);

                $bulkResult = $this->bulkInsertNewInvoices($group['new'], $manifest);
                $summary['invoices_inserted'] += $bulkResult['total'];

                // Posibles duplicadas aisladas: marcar + advertir. La
                // notificación a admins sale después del commit.
                if (! empty($group['duplicate_flags'])) {
                    $this->applyDuplicateFlags(
                        $group['duplicate_flags'],
                        $bulkResult['number_to_id'],
                        $manifest,
                        $summary,
                    );
                    $duplicateNotifyQueue[] = [$manifest, $group['duplicate_flags']];
                }

                foreach ($bulkResult['by_warehouse'] as $warehouseId => $count) {
                    $summary['inserted_warehouse_counts'][$warehouseId] =
                        ($summary['inserted_warehouse_counts'][$warehouseId] ?? 0) + $count;
                }

                $manifest->recalculateTotals();

                $this->logManifestImport(
                    $manifest,
                    $group['manifest_number'],
                    $importRecord,
                    $summary,
                    count($group['new']),
                );

                if (! empty($bulkResult['by_warehouse'])) {
                    $notifyQueue[] = [$manifest, $bulkResult['by_warehouse']];
                }
            }
        });

        foreach ($notifyQueue as [$manifest, $warehouseCounts]) {
            $this->notifyWarehouseUsers($manifest, $warehouseCounts);
        }

        foreach ($duplicateNotifyQueue as [$manifest, $flags]) {
            $this->notifyAdminsOfSuspectedDuplicates($manifest, $flags);
        }

        return $summary;
    }

    /**
     * Detecta facturas entrantes IDÉNTICAS a facturas ya registradas en la
     * ventana configurada (re-emisiones de Jaremar con número nuevo).
     *
     * La comparación es global: cada factura entrante contra TODAS las
     * facturas recientes de la BD (una sola query sobre el índice
     * (fingerprint, invoice_date)), sin importar en qué manifiesto viva la
     * original — Jaremar mezcla copias de varios manifiestos origen en un
     * mismo manifiesto nuevo.
     *
     * Exclusiones deliberadas:
     *   - Facturas sin client_id o sin líneas (huella null): sin base
     *     confiable de comparación.
     *   - La factura existente con el MISMO número: ese caso es reenvío,
     *     no re-emisión, y ya lo maneja el paso 3 (FACTURAS_YA_EXISTENTES).
     *   - Facturas soft-deleted: default scope de Eloquent; una factura
     *     eliminada no debe bloquear la re-entrada legítima.
     *
     * @param  array<int, array>  $invoices  Payload crudo del manifiesto entrante.
     * @return array<string, array{factura:string, identica_a:string, manifiesto_original:string, fecha_original:?string, cliente:?string, total:float, dias_diferencia:int, original_id:int}>
     *                                                                                                                                                                                         Keyed por Nfactura entrante.
     */
    protected function detectExactDuplicates(array $invoices): array
    {
        if (! config('invoices.duplicates.detection_enabled', true)) {
            return [];
        }

        $windowDays = max(0, (int) config('invoices.duplicates.window_days', 3));

        // Huella + fecha de cada factura entrante.
        $incoming = [];
        foreach ($invoices as $data) {
            $number = $data['Nfactura'] ?? null;
            $fingerprint = InvoiceFingerprint::fromPayload($data);
            $date = $this->parseDate($data['FechaFactura'] ?? null);

            if ($number && $fingerprint && $date) {
                $incoming[$number] = ['fingerprint' => $fingerprint, 'date' => $date];
            }
        }

        if (empty($incoming)) {
            return [];
        }

        $dates = array_column($incoming, 'date');
        $from = Carbon::parse(min($dates))->subDays($windowDays)->toDateString();
        $to = Carbon::parse(max($dates))->addDays($windowDays)->toDateString();

        // UNA query indexada para todo el grupo del manifiesto.
        $candidates = Invoice::query()
            ->with('manifest:id,number')
            ->whereIn('fingerprint', array_values(array_unique(array_column($incoming, 'fingerprint'))))
            ->whereBetween('invoice_date', [$from, $to])
            ->get()
            ->groupBy('fingerprint');

        $matches = [];

        foreach ($incoming as $number => $info) {
            $best = null;

            foreach ($candidates->get($info['fingerprint'], collect()) as $existing) {
                if ($existing->invoice_number === $number) {
                    continue; // reenvío del mismo número → lo maneja el paso 3
                }

                $days = (int) abs(Carbon::parse($info['date'])->diffInDays($existing->invoice_date));

                if ($days > $windowDays) {
                    continue;
                }

                if ($best === null || $days < $best['dias_diferencia']) {
                    $best = [
                        'factura' => (string) $number,
                        'identica_a' => (string) $existing->invoice_number,
                        'manifiesto_original' => (string) ($existing->manifest->number ?? ''),
                        'fecha_original' => $existing->invoice_date?->toDateString(),
                        'cliente' => $existing->client_name,
                        'total' => (float) $existing->total,
                        'dias_diferencia' => $days,
                        'original_id' => (int) $existing->id,
                    ];
                }
            }

            if ($best !== null) {
                $matches[$number] = $best;
            }
        }

        return $matches;
    }

    /**
     * Marca las facturas recién insertadas que son posibles duplicadas
     * AISLADAS (bajo el umbral de bloque): setea duplicate_of_invoice_id
     * hacia la original idéntica, deja rastro en ActivityLog y agrega la
     * advertencia al summary (viaja en la respuesta a Jaremar).
     *
     * Corre DENTRO de la transacción del lote.
     *
     * @param  array<string, array>  $flags  Matches de detectExactDuplicates.
     * @param  array<string, int>  $numberToId  Nfactura → id insertado.
     */
    protected function applyDuplicateFlags(array $flags, array $numberToId, Manifest $manifest, array &$summary): void
    {
        foreach ($flags as $number => $match) {
            $newId = $numberToId[$number] ?? null;

            if (! $newId) {
                continue;
            }

            DB::table('invoices')
                ->where('id', $newId)
                ->update(['duplicate_of_invoice_id' => $match['original_id']]);

            $summary['warnings'][] = "Factura {$number} importada como POSIBLE DUPLICADA de ".
                "{$match['identica_a']} (manifiesto #{$match['manifiesto_original']}, ".
                "{$match['fecha_original']}, mismo cliente, productos y total). Requiere revisión.";

            activity('api')
                ->performedOn($manifest)
                ->withProperties([
                    'source' => 'jaremar_api',
                    'factura' => $number,
                    'identica_a' => $match['identica_a'],
                    'manifiesto_original' => $match['manifiesto_original'],
                    'total' => $match['total'],
                    'dias_diferencia' => $match['dias_diferencia'],
                ])
                ->log("Factura {$number} importada como posible duplicada de {$match['identica_a']} (revisión pendiente).");
        }
    }

    /**
     * Notifica SOLO a super_admins las posibles duplicadas aisladas que
     * ENTRARON marcadas. A diferencia del rechazo en bloque (que Jaremar ve
     * en su respuesta), esto requiere decisión humana: ¿pedido legítimo
     * repetido o re-emisión que hay que devolver?
     *
     * Decisión de negocio (Mauricio, 2026-07-21): las alertas de duplicadas
     * son exclusivas del super_admin — admins/operativos NO deben verlas.
     * El resto de notificaciones del API conservan su audiencia original.
     *
     * Se ejecuta DESPUÉS del commit; nunca interrumpe la importación.
     *
     * @param  array<string, array>  $flags
     */
    protected function notifyAdminsOfSuspectedDuplicates(Manifest $manifest, array $flags): void
    {
        try {
            $admins = User::role('super_admin')->get();

            if ($admins->isEmpty()) {
                return;
            }

            $detalle = collect($flags)
                ->map(fn (array $m) => "{$m['factura']} ≈ {$m['identica_a']} ({$m['cliente']}, L. ".
                    number_format($m['total'], 2).')')
                ->implode('; ');

            $total = count($flags);

            foreach ($admins as $admin) {
                \Filament\Notifications\Notification::make()
                    ->title("Posible(s) factura(s) duplicada(s) en manifiesto #{$manifest->number}")
                    ->body("{$total} factura(s) entraron idénticas a facturas recientes de otro manifiesto: {$detalle}. ".
                        'Verifique con bodega si es pedido repetido legítimo o re-emisión de Jaremar (devolver).')
                    ->warning()
                    ->sendToDatabase($admin);
            }
        } catch (\Throwable $e) {
            Log::error('API Duplicadas: error notificando posibles duplicadas.', [
                'manifest' => $manifest->number,
                'error' => $e->getMessage(),
            ]);
        }
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
     *               'tiene_errores'         => bool,
     *               'manifiestos_invalidos' => [...],
     *               'manifiestos_validos'   => [...],
     *               }
     */
    public function validateManifestDatesForController(array $manifestNumbers, array $invoices): array
    {
        $today = now()->toDateString();
        $invalidos = [];
        $validos = [];

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
            if (! isset($existing[$number])) {
                $validos[] = [
                    'manifiesto' => $number,
                    'tipo' => 'nuevo',
                    'total_facturas' => count($facturasDelManifiesto),
                    'facturas' => $facturasDelManifiesto,
                    'nota' => 'Manifiesto nuevo — será creado al procesar el batch.',
                ];

                continue;
            }

            $manifest = $existing[$number];
            $createdDate = $manifest->created_at->toDateString();

            // Manifiesto de hoy — válido
            if ($createdDate === $today) {
                $validos[] = [
                    'manifiesto' => $number,
                    'tipo' => 'existente_hoy',
                    'total_facturas' => count($facturasDelManifiesto),
                    'facturas' => $facturasDelManifiesto,
                    'nota' => 'Manifiesto existente del día de hoy — se procesará normalmente.',
                ];

                continue;
            }

            // Manifiesto de día anterior — inválido
            $invalidos[] = [
                'manifiesto' => $number,
                'fecha_original' => $createdDate,
                'fecha_intento' => $today,
                'total_facturas' => count($facturasDelManifiesto),
                'facturas_afectadas' => $facturasDelManifiesto,
                'instruccion' => "El manifiesto #{$number} fue creado el {$createdDate} y ya no acepta facturas nuevas. Reenvíe estas facturas en un nuevo número de manifiesto.",
            ];
        }

        return [
            'tiene_errores' => ! empty($invalidos),
            'manifiestos_invalidos' => $invalidos,
            'manifiestos_validos' => $validos,
        ];
    }

    /**
     * Analiza un grupo de facturas de un manifiesto SIN escribir en BD.
     *
     * Estricto total (todo o nada): el manifiesto se rechaza completo si
     * hay almacén desconocido, está cerrado, o si CUALQUIER factura ya
     * existe en el sistema (en este u otro manifiesto, idéntica o con
     * cambios). Solo si TODAS las facturas son nuevas y válidas devuelve un
     * plan de inserción; el insert real lo hace processBatch dentro de una
     * transacción única para todo el lote.
     *
     * @return array{rejected: bool, rejection: ?array, plan: ?array}
     */
    protected function analyzeManifestGroup(
        string $manifestNumber,
        array $invoices,
        \Illuminate\Support\Collection $existingInvoices,
    ): array {
        $totalFacturas = count($invoices);

        // ── 1. Almacén desconocido ─────────────────────────────────────
        $warehouseErrors = $this->validateWarehouses($invoices);
        if (! empty($warehouseErrors)) {
            return $this->rejection($manifestNumber, $totalFacturas, 'ALMACENES_DESCONOCIDOS', [
                'almacenes_desconocidos' => $warehouseErrors,
                'mensaje' => "El manifiesto #{$manifestNumber} contiene almacenes no registrados en el sistema.",
            ]);
        }

        // ── 2. Manifiesto cerrado ──────────────────────────────────────
        $manifest = Manifest::where('number', $manifestNumber)->first();
        if ($manifest && $manifest->isClosed()) {
            return $this->rejection($manifestNumber, $totalFacturas, 'MANIFIESTO_CERRADO', [
                'mensaje' => "El manifiesto #{$manifestNumber} está cerrado y no acepta modificaciones.",
            ]);
        }

        // ── 3. Facturas que YA existen (estricto total) ────────────────
        // Cualquier factura ya registrada bloquea el lote. Distinguimos si
        // está en OTRO manifiesto (reenviar limpio) o en ESTE mismo (reenvío
        // de algo ya cargado → mandar solo lo nuevo).
        $enOtroManifiesto = [];
        $yaExistentes = [];

        foreach ($invoices as $invoice) {
            $invoiceNumber = $invoice['Nfactura'] ?? null;
            if (! $invoiceNumber) {
                continue;
            }

            $existing = $existingInvoices->get($invoiceNumber);
            if (! $existing) {
                continue; // factura nueva → ok
            }

            if ($manifest && $existing->manifest_id === $manifest->id) {
                $yaExistentes[] = ['factura' => $invoiceNumber];
            } else {
                $enOtroManifiesto[] = [
                    'factura' => $invoiceNumber,
                    'manifiesto_existente' => (string) $existing->manifest->number,
                ];
            }
        }

        if (! empty($enOtroManifiesto)) {
            return $this->rejection($manifestNumber, $totalFacturas, 'FACTURAS_DUPLICADAS_EN_OTRO_MANIFIESTO', [
                'facturas_duplicadas' => $enOtroManifiesto,
                'mensaje' => "El manifiesto #{$manifestNumber} contiene facturas que ya existen en otros manifiestos. Reenvíelo limpio.",
            ]);
        }

        if (! empty($yaExistentes)) {
            return $this->rejection($manifestNumber, $totalFacturas, 'FACTURAS_YA_EXISTENTES', [
                'facturas_existentes' => $yaExistentes,
                'mensaje' => "El manifiesto #{$manifestNumber} contiene facturas que ya fueron registradas. Reenvíe únicamente las facturas nuevas.",
            ]);
        }

        // ── 4. Duplicadas EXACTAS por huella (re-emisión de Jaremar) ──
        // Jaremar re-emite la misma factura económica con número fiscal
        // NUEVO en manifiesto NUEVO (mismo cliente, mismas líneas, mismo
        // total; solo cambian número, fechas y redondeo por línea). La
        // huella canónica (InvoiceFingerprint) las compara contra TODAS
        // las facturas de la ventana configurada, vivan en el manifiesto
        // que vivan. Dos niveles según la evidencia:
        //
        //   BLOQUE  (matches >= block_threshold): firma inequívoca de
        //           re-emisión masiva → rechazo del manifiesto completo.
        //   AISLADA (matches < block_threshold): puede ser un pedido
        //           legítimo repetido (pulperías con canasta fija) → la
        //           factura ENTRA pero marcada con duplicate_of_invoice_id
        //           para revisión humana + notificación a admins.
        $duplicateMatches = $this->detectExactDuplicates($invoices);
        $blockThreshold = max(1, (int) config('invoices.duplicates.block_threshold', 3));

        if (count($duplicateMatches) >= $blockThreshold) {
            return $this->rejection($manifestNumber, $totalFacturas, 'FACTURAS_DUPLICADAS_EXACTAS', [
                'facturas_duplicadas_exactas' => array_values(array_map(
                    fn (array $m) => [
                        'factura' => $m['factura'],
                        'identica_a' => $m['identica_a'],
                        'manifiesto_original' => $m['manifiesto_original'],
                        'fecha_original' => $m['fecha_original'],
                        'cliente' => $m['cliente'],
                        'total' => $m['total'],
                        'dias_diferencia' => $m['dias_diferencia'],
                    ],
                    $duplicateMatches,
                )),
                'mensaje' => "El manifiesto #{$manifestNumber} contiene ".count($duplicateMatches).
                    ' factura(s) idénticas a facturas ya registradas recientemente '.
                    '(mismo cliente, mismos productos y cantidades, mismo total) con número de factura distinto. '.
                    'Re-emisión duplicada detectada: NO reenvíe estas facturas. '.
                    'Las facturas no listadas pueden reenviarse en un manifiesto limpio.',
            ]);
        }

        // ── Limpio: todas las facturas son nuevas → plan de inserción ──
        // La fecha operacional la resuelve el validador (config
        // manifests.dates.manifest_date_source). Las fechas por factura ya
        // fueron validadas en el controller (V2/V3), así que es segura.
        $operationDate = $manifest
            ? null
            : ($this->dateValidator->resolveManifestOperationalDate($invoices) ?? now()->toDateString());

        return [
            'rejected' => false,
            'rejection' => null,
            'plan' => [
                'manifest' => $manifest,            // null → se crea en la fase de aplicar
                'manifest_number' => $manifestNumber,
                'operation_date' => $operationDate,
                'new' => $invoices,
                // Matches AISLADOS bajo el umbral de bloque: se insertan
                // marcados como posible duplicada (ver applyDuplicateFlags).
                'duplicate_flags' => $duplicateMatches,
            ],
        ];
    }

    /**
     * Construye una entrada de rechazo para manifiestos_rechazados[].
     *
     * @param  array<string, mixed>  $extra  Detalle específico del motivo.
     * @return array{rejected: bool, rejection: array, plan: null}
     */
    protected function rejection(string $manifestNumber, int $totalFacturas, string $motivo, array $extra): array
    {
        Log::warning("API Jaremar: manifiesto #{$manifestNumber} rechazado ({$motivo}).", [
            'total_facturas' => $totalFacturas,
        ]);

        return [
            'rejected' => true,
            'plan' => null,
            'rejection' => array_merge([
                'manifiesto' => $manifestNumber,
                'total_facturas' => $totalFacturas,
                'motivo' => $motivo,
            ], $extra),
        ];
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
        if (! empty($parts)) {
            $description .= ' ('.implode(', ', $parts).')';
        }

        activity('api')
            ->performedOn($manifest)
            ->withProperties([
                'batch_uuid' => $importRecord->batch_uuid,
                'source' => 'jaremar_api',
                'invoices_total' => $totalProcessed,
                'invoices_inserted' => $summary['invoices_inserted'],
                'invoices_unchanged' => $summary['invoices_unchanged'],
                'invoices_pending' => $summary['invoices_pending_review'],
                'invoices_rejected' => $summary['invoices_rejected'],
            ])
            ->log($description);
    }

    /**
     * Envía notificaciones a usuarios de bodegas que recibieron facturas nuevas.
     *
     * - Usuarios de bodega: reciben notificación solo de SU bodega.
     * - Usuarios globales (admin, super_admin, finance): reciben resumen de TODAS las bodegas.
     *
     * @param  array<int, int>  $warehouseCounts  [warehouse_id => cantidad_insertada]
     */
    protected function notifyWarehouseUsers(Manifest $manifest, array $warehouseCounts): void
    {
        if (empty($warehouseCounts)) {
            return;
        }

        try {
            $affectedIds = array_keys($warehouseCounts);
            $warehouseNames = Warehouse::whereIn('id', $affectedIds)->pluck('name', 'id');
            $totalInserted = array_sum($warehouseCounts);
            $manifestUrl = route('filament.admin.resources.manifests.view', $manifest);
            $notified = 0;

            // ── Notificar usuarios de cada bodega afectada ───────────
            // Multi-bodega: un usuario puede cubrir varias bodegas; le sumamos
            // las facturas de TODAS sus bodegas afectadas por este import.
            $warehouseUsers = User::query()
                ->whereHas('warehouses', fn ($q) => $q->whereIn('warehouses.id', $affectedIds))
                ->where('is_active', true)
                ->with('warehouses:id')
                ->get();

            foreach ($warehouseUsers as $user) {
                $userAffectedIds = array_values(array_intersect($user->warehouseIds(), $affectedIds));

                $count = 0;
                foreach ($userAffectedIds as $wid) {
                    $count += $warehouseCounts[$wid] ?? 0;
                }

                if ($count === 0) {
                    continue;
                }

                $warehouseName = collect($userAffectedIds)
                    ->map(fn (int $wid) => $warehouseNames[$wid] ?? 'Desconocida')
                    ->implode(', ');

                $user->notify(new InvoicesImported(
                    title: 'Nuevas facturas importadas',
                    body: "{$count} facturas importadas al manifiesto #{$manifest->number} para {$warehouseName}.",
                    actionUrl: $manifestUrl,
                    iconColor: 'success',
                ));
                $notified++;
            }

            // ── Notificar usuarios globales (sin bodega) con resumen ─
            $globalUsers = User::doesntHave('warehouses')
                ->where('is_active', true)
                ->get();

            if ($globalUsers->isNotEmpty()) {
                $breakdown = collect($warehouseCounts)
                    ->map(fn (int $count, int $id) => ($warehouseNames[$id] ?? '?').": {$count}")
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
                'global_users' => $globalUsers->count(),
                'warehouses' => $warehouseCounts,
            ]);

        } catch (\Throwable $e) {
            // No interrumpir el flujo de importación si falla la notificación
            Log::error('API Notificaciones: error al enviar.', [
                'manifest' => $manifest->number,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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

            if (! isset($this->warehouseMap[$code])) {
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
        $now = now()->toDateTimeString();
        $counts = ['total' => 0, 'by_warehouse' => [], 'number_to_id' => []];

        // ── 1. Preparar filas mapeadas ──────────────────────────────────
        $rows = [];
        foreach ($newInvoicesData as $data) {
            $mapped = $this->mapInvoiceFields($data, $manifest);
            $mapped['manifest_id'] = $manifest->id;
            $mapped['created_at'] = $now;
            $mapped['updated_at'] = $now;

            $rows[] = $mapped;
            $counts['total']++;

            if (! empty($mapped['warehouse_id'])) {
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
                $columns = array_keys($chunk[0]);
                $columnList = implode(', ', array_map(fn ($c) => "\"{$c}\"", $columns));
                $placeholder = '('.implode(', ', array_fill(0, count($columns), '?')).')';

                $valueRows = [];
                $bindings = [];
                foreach ($chunk as $row) {
                    $valueRows[] = $placeholder;
                    foreach ($columns as $c) {
                        $bindings[] = $row[$c];
                    }
                }

                $sql = "INSERT INTO \"invoices\" ({$columnList}) VALUES "
                     .implode(', ', $valueRows)
                     .' RETURNING "id", "invoice_number"';

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
            if (! $invoiceId || empty($data['LineasFactura'])) {
                continue;
            }

            foreach ($data['LineasFactura'] as $line) {
                $cantidadCaja = (float) ($line['CantidadCaja'] ?? 0);
                $factorConversion = max(1, (int) ($line['FactorConversion'] ?? 1));

                // quantity_fractions = TOTAL real de fracciones de la línea.
                // Normaliza el caso CJ puro (fractions=0, cajas>0) Y las líneas
                // MIXTAS de Jaremar (cajas>0 y fracciones>0 juntas, ej.
                // bonificación "1 caja + 56 uds"). Ver BoxEquivalence::totalFractions.
                $cantidadFracciones = BoxEquivalence::totalFractions(
                    (float) ($line['CantidadFracciones'] ?? 0),
                    $cantidadCaja,
                    $factorConversion,
                );

                $allLines[] = [
                    'invoice_id' => $invoiceId,
                    'jaremar_line_id' => $line['Id'] ?? null,
                    'invoice_jaremar_id' => isset($line['InvoiceId']) ? (int) $line['InvoiceId'] : null,
                    'line_number' => (int) ($line['NumeroLinea'] ?? 0),
                    'product_id' => (string) ($line['ProductoId'] ?? ''),
                    'product_description' => (string) ($line['ProductoDesc'] ?? ''),
                    'product_type' => $line['TipoProducto'] ?? null,
                    'unit_sale' => $line['UniVenta'] ?? null,
                    'quantity_fractions' => $cantidadFracciones,
                    'quantity_decimal' => (float) ($line['CantidadDecimal'] ?? 0),
                    'quantity_box' => $cantidadCaja,
                    'quantity_min_sale' => (float) ($line['CantidadUnidadMinVenta'] ?? 0),
                    'conversion_factor' => $factorConversion,
                    'cost' => (float) ($line['Costo'] ?? 0),
                    'price' => (float) ($line['Precio'] ?? 0),
                    'price_min_sale' => (float) ($line['PrecioUnidadMinVenta'] ?? 0),
                    'subtotal' => (float) ($line['Subtotal'] ?? 0),
                    'discount' => (float) ($line['Descuento'] ?? 0),
                    'discount_percent' => (float) ($line['PorcentajeDescuento'] ?? 0),
                    'tax' => (float) ($line['Impuesto'] ?? 0),
                    'tax_percent' => (float) ($line['PorcentajeImpuesto'] ?? 0),
                    'tax18' => (float) ($line['Impuesto18'] ?? 0),
                    'total' => (float) ($line['Total'] ?? 0),
                    'weight' => (float) ($line['Peso'] ?? 0),
                    'volume' => (float) ($line['Volumen'] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if (! empty($allLines)) {
            collect($allLines)->chunk(500)->each(function ($chunk) {
                DB::table('invoice_lines')->insert($chunk->values()->all());
            });
        }

        $counts['number_to_id'] = $invoiceNumberToId;

        return $counts;
    }

    protected function mapInvoiceFields(array $data, Manifest $manifest): array
    {
        $warehouseCode = $data['Almacen'] ?? null;
        $warehouseId = $this->warehouseMap[$warehouseCode] ?? null;

        return [
            'warehouse_id' => $warehouseId,
            'status' => $warehouseId ? 'imported' : 'pending_warehouse',
            // Huella de duplicado exacto — se persiste en el insert para que
            // futuras importaciones la comparen con una sola query indexada.
            'fingerprint' => InvoiceFingerprint::fromPayload($data),
            'jaremar_id' => $data['Id'] ?? null,
            'invoice_number' => $data['Nfactura'],
            'lx_number' => $data['NumeroFacturaLX'] ?? null,
            'order_number' => $data['NumeroPedido'] ?? null,
            'invoice_date' => $this->parseDate($data['FechaFactura']),
            'due_date' => $this->parseDate($data['FechaVencimiento'] ?? null),
            'print_limit_date' => $this->parseDate($data['FechaLimImpre'] ?? null),
            'seller_id' => $data['Vendedorid'] ?? null,
            'seller_name' => $data['Vendedor'] ?? null,
            'client_id' => $data['Clienteid'] ?? null,
            'client_name' => $data['Cliente'],
            'client_rtn' => $data['Rtn'] ?? null,
            'deliver_to' => $data['EntregarA'] ?? null,
            'department' => $data['Depto'] ?? null,
            'municipality' => $data['Municipio'] ?? null,
            'neighborhood' => $data['Barrio'] ?? null,
            'address' => $data['Direccion'] ?? null,
            'phone' => $data['Tel'] ?? null,
            'longitude' => $data['Longitud'] ?? null,
            'latitude' => $data['Latitud'] ?? null,
            'route_number' => trim($data['NumeroRuta'] ?? ''),
            'cai' => $data['Cai'] ?? null,
            'range_start' => $data['Rinicial'] ?? null,
            'range_end' => $data['Rfinal'] ?? null,
            'payment_type' => $data['TipoPago'] ?? null,
            'credit_days' => $data['DiasCred'] ?? 0,
            'invoice_type' => $data['TipoFactura'] ?? null,
            'invoice_status' => $data['EstadoFactura'] ?? 1,
            'matriz_address' => $data['DirCasaMatriz'] ?? null,
            'branch_address' => $data['DirSucursal'] ?? null,
            'importe_excento' => $data['ImporteExcento'] ?? 0,
            'importe_exento_desc' => $data['ImporteExento_Desc'] ?? 0,
            'importe_exento_isv18' => $data['ImporteExento_ISV18'] ?? 0,
            'importe_exento_isv15' => $data['ImporteExento_ISV15'] ?? 0,
            'importe_exento_total' => $data['ImporteExento_Total'] ?? 0,
            'importe_exonerado' => $data['ImporteExonerado'] ?? 0,
            'importe_exonerado_desc' => $data['ImporteExonerado_Desc'] ?? 0,
            'importe_exonerado_isv18' => $data['ImporteExonerado_ISV18'] ?? 0,
            'importe_exonerado_isv15' => $data['ImporteExonerado_ISV15'] ?? 0,
            'importe_exonerado_total' => $data['ImporteExonerado_Total'] ?? 0,
            'importe_gravado' => $data['ImporteGrabado'] ?? 0,
            'importe_gravado_desc' => $data['ImporteGravado_Desc'] ?? 0,
            'importe_gravado_isv18' => $data['ImporteGravado_ISV18'] ?? 0,
            'importe_gravado_isv15' => $data['ImporteGravado_ISV15'] ?? 0,
            'importe_gravado_total' => $data['ImporteGravado_Total'] ?? 0,
            'discounts' => $data['DescuentosRebajas'] ?? 0,
            'isv18' => $data['Isv18'] ?? 0,
            'isv15' => $data['Isv15'] ?? 0,
            'total' => $data['Total'],
        ];
    }

    /**
     * Crea un Manifest nuevo cuando Jaremar manda un NumeroManifiesto
     * que aún no existe en BD.
     *
     * @param  string  $manifestNumber  Identificador único de Jaremar
     * @param  string|null  $operationDate  YYYY-MM-DD — fecha operativa real
     *                                      derivada de FechaFactura. Si es
     *                                      null, fallback a hoy (solo para
     *                                      retrocompatibilidad con tests
     *                                      legacy que no la proveen).
     */
    protected function createManifest(string $manifestNumber, ?string $operationDate = null): Manifest
    {
        $supplier = Supplier::where('is_active', true)->first()
            ?? throw new \RuntimeException(
                'No se encontró ningún proveedor activo en el sistema. '.
                'Configure al menos un proveedor activo antes de importar facturas vía API.'
            );

        $resolvedDate = $operationDate ?? now()->toDateString();
        $today = now()->toDateString();
        $isRetroactive = $resolvedDate < $today;

        $manifest = Manifest::create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => null,
            'number' => $manifestNumber,
            'date' => $resolvedDate,
            'status' => 'imported',
            'created_by' => null,
            'updated_by' => null,
        ]);

        // Activity Log enriquecido: cuando la fecha operativa es retroactiva
        // (Jaremar atrasó el envío), lo dejamos visible en propiedades para
        // que admins puedan filtrar manifiestos retroactivos en reportería.
        $properties = ['source' => 'jaremar_api', 'operation_date' => $resolvedDate];
        $description = "Manifiesto #{$manifestNumber} creado via API de Jaremar.";

        if ($isRetroactive) {
            $properties['retroactive_days'] = (int) (\Carbon\Carbon::parse($resolvedDate)
                ->diffInDays(\Carbon\Carbon::parse($today)));
            $description .= " (retroactivo, fecha operativa: {$resolvedDate}).";
        }

        activity('api')
            ->performedOn($manifest)
            ->withProperties($properties)
            ->log($description);

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
     * @return string|null Fecha en formato Y-m-d, o null si no se pudo parsear.
     */
    protected function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

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
