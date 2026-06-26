<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiInvoiceImport;
use App\Models\Manifest;
use App\Models\User;
use App\Services\ApiInvoiceImporterService;
use App\Services\ApiInvoiceValidatorService;
use App\Services\ManifestDateValidator;
use Filament\Notifications\Notification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ManifestApiController extends Controller
{
    public function __construct(
        private readonly ApiInvoiceValidatorService $validator,
        private readonly ApiInvoiceImporterService $importer,
        private readonly ManifestDateValidator $dateValidator,
    ) {}

    /**
     * POST /api/v1/facturas/insertar
     *
     * Pipeline de validación en orden estricto de prioridad:
     *
     *   1. Parsear body JSON
     *   2. Validar estructura del payload (campos requeridos)
     *   3. Validar fechas del batch (ManifestDateValidator) ← FILTRO TEMPRANO
     *      - V1: homogeneidad de FechaFactura por NumeroManifiesto
     *      - V2: FechaFactura no puede ser futura
     *      - V3: FechaFactura no puede superar max_backdate_days
     *      - Si hay errores → rechaza TODO el batch con 422 + notifica admins
     *   4. Validar fechas de manifiestos existentes (regla "no agregar a viejo")
     *      - Separa manifiestos inválidos (día anterior) de válidos (hoy/nuevo)
     *      - Si hay inválidos → rechaza TODO el batch
     *   5. Detectar batch duplicado (hash) — solo aplica para batches del día
     *   6. Persistir payload crudo
     *   7. Procesar batch (validación de almacenes + inserción)
     */
    public function insertar(Request $request): JsonResponse
    {
        $apiKey = $request->header('ApiKey', '');
        $keyHint = substr($apiKey, 0, 8);

        // ── 1. Parsear el body ─────────────────────────────────────────
        $invoices = $request->json()->all();

        if (! is_array($invoices) || empty($invoices)) {
            Log::warning('API Jaremar: body inválido recibido.', [
                'ip' => $request->ip(),
                'key_hint' => $keyHint,
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'El body debe ser un array JSON de facturas no vacío.',
            ], 422);
        }

        // ── 2. Validar estructura del payload ──────────────────────────
        if (! $this->validator->validate($invoices)) {
            Log::warning('API Jaremar: validación de estructura fallida.', [
                'ip' => $request->ip(),
                'errors' => $this->validator->getErrors(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'El payload contiene errores de validación.',
                'errores' => $this->validator->getErrors(),
            ], 422);
        }

        // ── 3. Validar fechas del batch (V1/V2/V3) ─────────────────────
        // Filtro temprano: si Jaremar manda data con fechas inválidas en
        // origen (mezcladas, futuras o demasiado antiguas), rechazamos
        // ANTES de tocar BD, antes del hash detector, antes de la lógica
        // de manifiestos existentes. Es la línea de defensa más temprana
        // contra data corrupta.
        //
        // Reglas (configurables en config/manifests.php):
        //   V1 — FECHAS_MEZCLADAS:           OPCIONAL (default OFF). Un
        //                                    manifiesto puede traer facturas
        //                                    de varias fechas salvo que se
        //                                    active reject_mixed_dates.
        //   V2 — FECHA_FACTURA_FUTURA:       ninguna FechaFactura > hoy (TZ HN).
        //   V3 — FECHA_FACTURA_DEMASIADO_   ninguna FechaFactura con más de
        //        ANTIGUA                     N días de antigüedad (default 30).
        //                                    V2/V3 se evalúan POR FACTURA; una
        //                                    sola mala rechaza el manifiesto.
        $batchDateValidation = $this->dateValidator->validateBatch($invoices);

        if ($batchDateValidation['has_errors']) {
            // Los rechazos por fecha son errores de datos en ORIGEN (Jaremar),
            // no acciones de Hosana. Por eso la notificación in-app es opcional
            // (default OFF). El log y la respuesta del API siempre quedan.
            if (config('manifests.dates.notify_admins_on_date_rejection', false)) {
                $this->notifyAdminsForDateValidation($batchDateValidation['invalid_manifests']);
            }

            Log::warning('API Jaremar: batch rechazado por validación de fechas (V1/V2/V3).', [
                'ip' => $request->ip(),
                'key_hint' => $keyHint,
                'invalid_manifests' => array_map(
                    fn ($m) => ['manifiesto' => $m['manifiesto'], 'motivo' => $m['motivo']],
                    $batchDateValidation['invalid_manifests']
                ),
            ]);

            $totalInvalid = array_sum(array_column($batchDateValidation['invalid_manifests'], 'total_facturas'));
            $totalValid = array_sum(array_column($batchDateValidation['valid_manifests'], 'total_facturas'));

            return new JsonResponse([
                'success' => false,
                'message' => 'Batch rechazado por errores de fecha en uno o más manifiestos.',
                'motivo' => 'FECHAS_INVALIDAS',
                'accion_requerida' => 'Revise los manifiestos rechazados. Ninguna FechaFactura puede ser futura ni superar el rango configurado de antigüedad; una sola factura inválida rechaza el manifiesto completo.',
                'resumen' => [
                    'total_recibidas' => count($invoices),
                    'total_rechazadas' => $totalInvalid,
                    'total_validas' => $totalValid,
                    'insertadas' => 0,
                ],
                'manifiestos_rechazados' => $batchDateValidation['invalid_manifests'],
                'manifiestos_validos' => $batchDateValidation['valid_manifests'],
            ], 422);
        }

        // ── 4. Validar fechas de manifiestos existentes ────────────────
        // Regla complementaria a V1/V2/V3: si Jaremar intenta agregar
        // facturas a un manifiesto que YA EXISTE en BD y fue creado
        // antes de hoy, rechazamos. Esto previene que un manifiesto
        // crezca día tras día con nuevas facturas.
        //
        // El resultado separa el batch en dos grupos:
        //   - manifiestos_invalidos: creados antes de hoy → causan rechazo total
        //   - manifiestos_validos:   nuevos o creados hoy → son procesables
        //
        // Si hay inválidos, Jaremar recibe:
        //   1. Qué manifiestos rechazar y por qué (con lista de facturas)
        //   2. Qué manifiestos son correctos pero deben reenviarse solos
        $manifestNumbers = collect($invoices)
            ->pluck('NumeroManifiesto')
            ->unique()
            ->values()
            ->all();

        $fechaValidacion = $this->importer->validateManifestDatesForController(
            $manifestNumbers,
            $invoices
        );

        if ($fechaValidacion['tiene_errores']) {
            $invalidos = $fechaValidacion['manifiestos_invalidos'];
            $validos = $fechaValidacion['manifiestos_validos'];
            $totalRechazadas = array_sum(array_column($invalidos, 'total_facturas'));
            $totalValidas = array_sum(array_column($validos, 'total_facturas'));

            $this->notifyAdmins($invalidos, 'pre-persistencia');

            Log::warning('API Jaremar: batch rechazado por manifiestos de días anteriores.', [
                'ip' => $request->ip(),
                'key_hint' => $keyHint,
                'manifiestos_invalidos' => array_column($invalidos, 'manifiesto'),
                'manifiestos_validos' => array_column($validos, 'manifiesto'),
                'total_rechazadas' => $totalRechazadas,
            ]);

            $response = [
                'success' => false,
                'message' => 'Batch rechazado completamente. Contiene facturas de manifiestos creados en días anteriores que ya no aceptan nuevas facturas.',
                'motivo' => 'MANIFIESTOS_FECHA_INVALIDA',
                'accion_requerida' => 'Corrija el batch separando los manifiestos afectados. Los manifiestos válidos deben reenviarse en un batch independiente sin mezclar con los del día anterior.',
                'resumen' => [
                    'total_recibidas' => count($invoices),
                    'total_rechazadas' => $totalRechazadas,
                    'total_validas' => $totalValidas,
                    'insertadas' => 0,
                ],
                'manifiestos_rechazados' => $invalidos,
            ];

            // Informar cuáles manifiestos SÍ eran válidos para que
            // Jaremar los reenvíe solos sin tener que buscarlos
            if (! empty($validos)) {
                $response['manifiestos_no_afectados'] = array_map(
                    fn ($v) => [
                        'manifiesto' => $v['manifiesto'],
                        'total_facturas' => $v['total_facturas'],
                        'facturas' => $v['facturas'],
                        'nota' => 'Este manifiesto es válido pero fue rechazado por venir en el mismo batch que manifiestos de días anteriores. Reenvíelo en un batch independiente.',
                    ],
                    $validos
                );
            }

            return new JsonResponse($response, 422);
        }

        // ── 5. Detectar batch duplicado (hash) ─────────────────────────
        // Idempotencia: si este payload ya fue procesado (registro activo,
        // status != 'failed'), respondemos con el resultado original en vez
        // de re-procesar. El alcance NO se filtra por fecha para coincidir
        // con el índice único parcial `..._payload_hash_active_unique`
        // (también global): así el pre-chequeo y la constraint son consistentes
        // y un reenvío idéntico de otro día no termina en un 500.
        $payloadHash = hash('sha256', $request->getContent());
        $duplicate = ApiInvoiceImport::where('payload_hash', $payloadHash)
            ->where('status', '!=', 'failed')
            ->first();

        if ($duplicate) {
            return $this->duplicateBatchResponse($duplicate);
        }

        // ── 6. Persistir el payload crudo ──────────────────────────────
        // El índice único parcial sobre payload_hash es la última línea de
        // defensa ante una carrera: dos peticiones idénticas simultáneas que
        // ambas pasan el chequeo del paso 5. Si la constraint salta,
        // respondemos idempotente con el registro existente en vez de un 500.
        try {
            $importRecord = ApiInvoiceImport::create([
                'batch_uuid' => Str::uuid()->toString(),
                'api_key_hint' => $keyHint,
                'ip_address' => $request->ip(),
                'total_received' => count($invoices),
                'raw_payload' => $invoices,
                'payload_hash' => $payloadHash,
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $existing = ApiInvoiceImport::where('payload_hash', $payloadHash)
                ->where('status', '!=', 'failed')
                ->first();

            if ($existing) {
                return $this->duplicateBatchResponse($existing);
            }

            throw $e;
        }

        // ── 7. Procesar el batch ───────────────────────────────────────
        try {
            $summary = $this->importer->processBatch($invoices, $importRecord);
            $importRecord->markAsProcessed($summary);

            Log::info('API Jaremar: batch procesado correctamente.', [
                'batch_uuid' => $importRecord->batch_uuid,
                'ip' => $request->ip(),
                'summary' => $summary,
            ]);

            // Notificar a admins si hubo rechazos por almacén desconocido
            if (! empty($summary['manifiestos_rechazados'])) {
                $this->notifyAdmins(
                    $summary['manifiestos_rechazados'],
                    $importRecord->batch_uuid
                );
            }

            // ── 8. Construir respuesta para Jaremar ────────────────────
            $manifiestos = collect($invoices)
                ->pluck('NumeroManifiesto')
                ->unique()
                ->values()
                ->all();

            $response = [
                'success' => true,
                'message' => 'Facturas procesadas correctamente.',
                'batch_uuid' => $importRecord->batch_uuid,
                'manifiestos' => $manifiestos,
                'resumen' => [
                    'recibidas' => count($invoices),
                    'insertadas' => $summary['invoices_inserted'],
                    'actualizadas' => $summary['invoices_updated'],
                    'sin_cambios' => $summary['invoices_unchanged'],
                    'pendientes_revision' => $summary['invoices_pending_review'],
                    'rechazadas' => $summary['invoices_rejected'],
                ],
            ];

            // Detalle de manifiestos rechazados (3 motivos posibles):
            //   - ALMACENES_DESCONOCIDOS              (paso 1 del service)
            //   - MANIFIESTO_CERRADO                  (paso 2 del service)
            //   - FACTURAS_DUPLICADAS_EN_OTRO_MANIFIESTO  (paso 2.5 del service)
            //
            // Si todos los rechazos comparten el MISMO motivo, devolvemos
            // un response.motivo único y un message específico para Jaremar.
            // Si hay motivos mezclados, motivo='MOTIVOS_MIXTOS' y Jaremar
            // debe leer cada entry de manifiestos_rechazados[] que ya trae
            // su propio motivo discriminador.
            if (! empty($summary['manifiestos_rechazados'])) {
                $response['success'] = false;

                $motivosUnicos = collect($summary['manifiestos_rechazados'])
                    ->pluck('motivo')
                    ->unique()
                    ->values()
                    ->all();

                if (count($motivosUnicos) === 1) {
                    $response['motivo'] = $motivosUnicos[0];
                    $response['message'] = match ($motivosUnicos[0]) {
                        'ALMACENES_DESCONOCIDOS' => 'Uno o más manifiestos fueron rechazados por contener almacenes no registrados en el sistema.',
                        'MANIFIESTO_CERRADO' => 'Uno o más manifiestos fueron rechazados porque ya están cerrados y no aceptan modificaciones.',
                        'FACTURAS_DUPLICADAS_EN_OTRO_MANIFIESTO' => 'Uno o más manifiestos fueron rechazados por contener facturas que ya existen en otros manifiestos.',
                        default => 'Uno o más manifiestos fueron rechazados.',
                    };
                } else {
                    $response['motivo'] = 'MOTIVOS_MIXTOS';
                    $response['message'] = 'Uno o más manifiestos fueron rechazados por distintos motivos. Revisar manifiestos_rechazados[].';
                }

                $response['manifiestos_rechazados'] = array_map(
                    fn ($r) => $this->shapeRejectedManifestEntry($r),
                    $summary['manifiestos_rechazados']
                );
            }

            if (! empty($summary['warnings'])) {
                $response['advertencias'] = $summary['warnings'];
            }

            if (! empty($summary['errors'])) {
                $response['rechazadas_detalle'] = $summary['errors'];
            }

            $statusCode = ! empty($summary['manifiestos_rechazados']) ? 422 : 200;

            return new JsonResponse($response, $statusCode);

        } catch (\Throwable $e) {
            $importRecord->markAsFailed($e->getMessage());

            Log::error('API Jaremar: error procesando batch.', [
                'batch_uuid' => $importRecord->batch_uuid,
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Error interno al procesar las facturas. El equipo técnico ha sido notificado.',
                'batch_uuid' => $importRecord->batch_uuid,
            ], 500);
        }
    }

    /**
     * Respuesta idempotente para un batch ya procesado (mismo payload_hash).
     *
     * Reutilizada por el pre-chequeo de duplicados (paso 5) y por el catch
     * de la carrera en el insert (paso 6). Devuelve 200 con el resumen del
     * import original, de modo que reenviar el mismo payload nunca produzca
     * un 500 y Jaremar reciba un resultado consistente.
     */
    private function duplicateBatchResponse(ApiInvoiceImport $import): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'message' => 'Este batch ya fue procesado anteriormente.',
            'batch_uuid' => $import->batch_uuid,
            'resumen' => [
                'recibidas' => $import->total_received,
                'insertadas' => $import->invoices_inserted,
                'actualizadas' => $import->invoices_updated,
                'sin_cambios' => $import->invoices_unchanged,
                'pendientes_revision' => $import->invoices_pending_review,
                'rechazadas' => $import->invoices_rejected,
            ],
        ], 200);
    }

    /**
     * GET /api/v1/manifiestos/{numero}/estado
     */
    public function estado(string $numero): JsonResponse
    {
        $manifest = Manifest::where('number', $numero)->first();

        if (! $manifest) {
            return new JsonResponse([
                'success' => false,
                'message' => "El manifiesto #{$numero} no existe en el sistema.",
            ], 404);
        }

        return new JsonResponse([
            'success' => true,
            'manifiesto' => $numero,
            'estado' => $manifest->status,
            'resumen' => [
                'total_facturas' => $manifest->invoices_count,
                'total_importe' => (float) $manifest->total_invoices,
                'fecha_ingreso' => $manifest->created_at->toDateTimeString(),
            ],
        ], 200);
    }

    /**
     * Notifica admins y super_admins cuando el batch falla la validación
     * de fechas en V1/V2/V3 (ManifestDateValidator).
     *
     * Esta notificación es diferente de notifyAdmins() porque la estructura
     * del payload de error es distinta: trae 'motivo' (FECHAS_MEZCLADAS,
     * FECHA_FACTURA_FUTURA, FECHA_FACTURA_DEMASIADO_ANTIGUA) y 'detalle'
     * con metadata específica de la regla violada.
     */

    /**
     * Da forma al payload de una entry de manifiestos_rechazados[] según
     * su motivo. Cada motivo tiene campos propios — esto evita un response
     * heterogéneo y mantiene el contrato con Jaremar limpio.
     *
     * @param  array  $rejected  Entry tal como la guardó el service.
     * @return array<string, mixed>
     */
    private function shapeRejectedManifestEntry(array $rejected): array
    {
        $base = [
            'manifiesto' => $rejected['manifiesto'],
            'total_facturas' => $rejected['total_facturas'],
            'motivo' => $rejected['motivo'],
        ];

        return match ($rejected['motivo']) {
            'ALMACENES_DESCONOCIDOS' => array_merge($base, [
                'almacenes_desconocidos' => array_map(
                    fn ($codigo, $facturas) => [
                        'almacen' => $codigo,
                        'facturas' => $facturas,
                        'cantidad' => count($facturas),
                    ],
                    array_keys($rejected['almacenes_desconocidos']),
                    array_values($rejected['almacenes_desconocidos'])
                ),
            ]),

            'MANIFIESTO_CERRADO' => array_merge($base, [
                'mensaje' => $rejected['mensaje'],
            ]),

            'FACTURAS_DUPLICADAS_EN_OTRO_MANIFIESTO' => array_merge($base, [
                'mensaje' => $rejected['mensaje'],
                'facturas_duplicadas' => $rejected['facturas_duplicadas'],
            ]),

            default => $base,
        };
    }

    private function notifyAdminsForDateValidation(array $invalidManifests): void
    {
        $admins = User::role(['super_admin', 'admin'])->get();

        if ($admins->isEmpty()) {
            return;
        }

        foreach ($invalidManifests as $invalid) {
            $manifiesto = $invalid['manifiesto'];
            $motivo = $invalid['motivo'];
            $total = $invalid['total_facturas'];

            $titulo = match ($motivo) {
                'FECHAS_MEZCLADAS' => "Manifiesto #{$manifiesto} rechazado — fechas mezcladas",
                'FECHA_FACTURA_FUTURA' => "Manifiesto #{$manifiesto} rechazado — fecha futura",
                'FECHA_FACTURA_DEMASIADO_ANTIGUA' => "Manifiesto #{$manifiesto} rechazado — fecha demasiado antigua",
                'FECHA_FACTURA_INVALIDA' => "Manifiesto #{$manifiesto} rechazado — fecha no parseable",
                default => "Manifiesto #{$manifiesto} rechazado",
            };

            $cuerpo = match ($motivo) {
                'FECHAS_MEZCLADAS' => "Jaremar intentó enviar el manifiesto #{$manifiesto} ".
                    'con facturas de fechas distintas: '.
                    implode(', ', $invalid['detalle']['fechas_encontradas'] ?? []).
                    ". Total {$total} factura(s) afectada(s).",
                'FECHA_FACTURA_FUTURA' => "El manifiesto #{$manifiesto} tiene factura(s) con ".
                    "FechaFactura posterior a hoy ({$invalid['detalle']['hoy_servidor']}): ".
                    implode(', ', array_keys($invalid['detalle']['facturas_futuras'] ?? [])).
                    ". {$total} factura(s) en el manifiesto.",
                'FECHA_FACTURA_DEMASIADO_ANTIGUA' => "El manifiesto #{$manifiesto} tiene factura(s) que ".
                    "superan el límite de {$invalid['detalle']['limite_dias']} días de antigüedad: ".
                    implode(', ', array_keys($invalid['detalle']['facturas_antiguas'] ?? [])).
                    ". Requiere carga manual desde el panel. {$total} factura(s) en el manifiesto.",
                default => "El manifiesto #{$manifiesto} fue rechazado por validación de fecha. {$total} factura(s).",
            };

            foreach ($admins as $admin) {
                Notification::make()
                    ->title($titulo)
                    ->body($cuerpo)
                    ->danger()
                    ->sendToDatabase($admin);
            }
        }

        Log::info('API Jaremar: notificación enviada a admins por validación de fechas.', [
            'manifiestos' => array_map(
                fn ($m) => ['manifiesto' => $m['manifiesto'], 'motivo' => $m['motivo']],
                $invalidManifests
            ),
            'admins_notificados' => $admins->pluck('name')->toArray(),
        ]);
    }

    /**
     * Notifica a todos los admins y super_admins cuando un manifiesto
     * es rechazado — por fecha inválida o almacén desconocido.
     */
    private function notifyAdmins(array $manifistosRechazados, string $batchUuid): void
    {
        $admins = User::role(['super_admin', 'admin'])->get();

        if ($admins->isEmpty()) {
            return;
        }

        foreach ($manifistosRechazados as $rechazado) {
            $manifiesto = $rechazado['manifiesto'];

            if (isset($rechazado['fecha_original'])) {
                $titulo = "Manifiesto #{$manifiesto} rechazado — fecha inválida";
                $cuerpo = "Jaremar intentó agregar {$rechazado['total_facturas']} factura(s) al manifiesto #{$manifiesto} ".
                          "el día {$rechazado['fecha_intento']}, pero ese manifiesto fue creado el {$rechazado['fecha_original']} ".
                          'y ya no acepta facturas nuevas. Se rechazó el batch completo.';
            } else {
                $total = $rechazado['total_facturas'];
                $codigos = implode(', ', array_keys($rechazado['almacenes_desconocidos']));
                $titulo = "Manifiesto #{$manifiesto} rechazado — almacén desconocido";
                $cuerpo = "El manifiesto #{$manifiesto} fue rechazado. ".
                           "Contiene {$total} factura(s) con almacén(es) no registrado(s): {$codigos}. ".
                           'Jaremar debe registrar el almacén o corregir el código y reenviar.';
            }

            foreach ($admins as $admin) {
                Notification::make()
                    ->title($titulo)
                    ->body($cuerpo)
                    ->danger()
                    ->sendToDatabase($admin);
            }
        }

        Log::info('API Jaremar: notificación enviada a admins por manifiestos rechazados.', [
            'batch_uuid' => $batchUuid,
            'manifiestos' => array_column($manifistosRechazados, 'manifiesto'),
            'admins_notificados' => $admins->pluck('name')->toArray(),
        ]);
    }
}
