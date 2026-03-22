<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiInvoiceImport;
use App\Models\Manifest;
use App\Models\User;
use App\Services\ApiInvoiceImporterService;
use App\Services\ApiInvoiceValidatorService;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ManifestApiController extends Controller
{
    public function __construct(
        private readonly ApiInvoiceValidatorService $validator,
        private readonly ApiInvoiceImporterService  $importer,
    ) {}

    /**
     * POST /api/v1/facturas/insertar
     *
     * Pipeline de validación en orden estricto de prioridad:
     *
     *   1. Parsear body JSON
     *   2. Validar estructura del payload (campos requeridos)
     *   3. Validar fechas de manifiestos ← ANTES del hash detector
     *      - Separa manifiestos inválidos (día anterior) de válidos (hoy/nuevo)
     *      - Si hay inválidos → rechaza TODO el batch
     *      - Jaremar recibe qué rechazar Y qué reenviar por separado
     *   4. Detectar batch duplicado (hash) — solo aplica para batches del día
     *   5. Persistir payload crudo
     *   6. Procesar batch (validación de almacenes + inserción)
     */
    public function insertar(Request $request): JsonResponse
    {
        $apiKey  = $request->header('ApiKey', '');
        $keyHint = substr($apiKey, 0, 8);

        // ── 1. Parsear el body ─────────────────────────────────────────
        $invoices = $request->json()->all();

        if (!is_array($invoices) || empty($invoices)) {
            Log::warning('API Jaremar: body inválido recibido.', [
                'ip'       => $request->ip(),
                'key_hint' => $keyHint,
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'El body debe ser un array JSON de facturas no vacío.',
            ], 422);
        }

        // ── 2. Validar estructura del payload ──────────────────────────
        if (!$this->validator->validate($invoices)) {
            Log::warning('API Jaremar: validación de estructura fallida.', [
                'ip'     => $request->ip(),
                'errors' => $this->validator->getErrors(),
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'El payload contiene errores de validación.',
                'errores' => $this->validator->getErrors(),
            ], 422);
        }

        // ── 3. Validar fechas de manifiestos ───────────────────────────
        // CRÍTICO: Va ANTES del detector de duplicados.
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
            $invalidos       = $fechaValidacion['manifiestos_invalidos'];
            $validos         = $fechaValidacion['manifiestos_validos'];
            $totalRechazadas = array_sum(array_column($invalidos, 'total_facturas'));
            $totalValidas    = array_sum(array_column($validos, 'total_facturas'));

            $this->notifyAdmins($invalidos, 'pre-persistencia');

            Log::warning('API Jaremar: batch rechazado por manifiestos de días anteriores.', [
                'ip'               => $request->ip(),
                'key_hint'         => $keyHint,
                'manifiestos_invalidos' => array_column($invalidos, 'manifiesto'),
                'manifiestos_validos'   => array_column($validos, 'manifiesto'),
                'total_rechazadas' => $totalRechazadas,
            ]);

            $response = [
                'success'          => false,
                'message'          => 'Batch rechazado completamente. Contiene facturas de manifiestos creados en días anteriores que ya no aceptan nuevas facturas.',
                'motivo'           => 'MANIFIESTOS_FECHA_INVALIDA',
                'accion_requerida' => 'Corrija el batch separando los manifiestos afectados. Los manifiestos válidos deben reenviarse en un batch independiente sin mezclar con los del día anterior.',
                'resumen'          => [
                    'total_recibidas'  => count($invoices),
                    'total_rechazadas' => $totalRechazadas,
                    'total_validas'    => $totalValidas,
                    'insertadas'       => 0,
                ],
                'manifiestos_rechazados' => $invalidos,
            ];

            // Informar cuáles manifiestos SÍ eran válidos para que
            // Jaremar los reenvíe solos sin tener que buscarlos
            if (!empty($validos)) {
                $response['manifiestos_no_afectados'] = array_map(
                    fn ($v) => [
                        'manifiesto'     => $v['manifiesto'],
                        'total_facturas' => $v['total_facturas'],
                        'facturas'       => $v['facturas'],
                        'nota'           => "Este manifiesto es válido pero fue rechazado por venir en el mismo batch que manifiestos de días anteriores. Reenvíelo en un batch independiente.",
                    ],
                    $validos
                );
            }

            return new JsonResponse($response, 422);
        }

        // ── 4. Detectar batch duplicado (hash) ─────────────────────────
        // Solo llegamos aquí si todas las fechas son válidas.
        // Aplica únicamente para batches procesados hoy.
        $payloadHash = hash('sha256', $request->getContent());
        $duplicate   = ApiInvoiceImport::where('payload_hash', $payloadHash)
                                       ->where('status', '!=', 'failed')
                                       ->whereDate('created_at', today())
                                       ->first();

        if ($duplicate) {
            return new JsonResponse([
                'success'    => true,
                'message'    => 'Este batch ya fue procesado anteriormente el día de hoy.',
                'batch_uuid' => $duplicate->batch_uuid,
                'resumen'    => [
                    'recibidas'           => $duplicate->total_received,
                    'insertadas'          => $duplicate->invoices_inserted,
                    'actualizadas'        => $duplicate->invoices_updated,
                    'sin_cambios'         => $duplicate->invoices_unchanged,
                    'pendientes_revision' => $duplicate->invoices_pending_review,
                    'rechazadas'          => $duplicate->invoices_rejected,
                ],
            ], 200);
        }

        // ── 5. Persistir el payload crudo ──────────────────────────────
        $importRecord = ApiInvoiceImport::create([
            'batch_uuid'     => Str::uuid()->toString(),
            'api_key_hint'   => $keyHint,
            'ip_address'     => $request->ip(),
            'total_received' => count($invoices),
            'raw_payload'    => $invoices,
            'payload_hash'   => $payloadHash,
            'status'         => 'received',
        ]);

        // ── 6. Procesar el batch ───────────────────────────────────────
        try {
            $summary = $this->importer->processBatch($invoices, $importRecord);
            $importRecord->markAsProcessed($summary);

            Log::info('API Jaremar: batch procesado correctamente.', [
                'batch_uuid' => $importRecord->batch_uuid,
                'ip'         => $request->ip(),
                'summary'    => $summary,
            ]);

            // Notificar a admins si hubo rechazos por almacén desconocido
            if (!empty($summary['manifiestos_rechazados'])) {
                $this->notifyAdmins(
                    $summary['manifiestos_rechazados'],
                    $importRecord->batch_uuid
                );
            }

            // ── 7. Construir respuesta para Jaremar ────────────────────
            $manifiestos = collect($invoices)
                ->pluck('NumeroManifiesto')
                ->unique()
                ->values()
                ->all();

            $response = [
                'success'     => true,
                'message'     => 'Facturas procesadas correctamente.',
                'batch_uuid'  => $importRecord->batch_uuid,
                'manifiestos' => $manifiestos,
                'resumen'     => [
                    'recibidas'           => count($invoices),
                    'insertadas'          => $summary['invoices_inserted'],
                    'actualizadas'        => $summary['invoices_updated'],
                    'sin_cambios'         => $summary['invoices_unchanged'],
                    'pendientes_revision' => $summary['invoices_pending_review'],
                    'rechazadas'          => $summary['invoices_rejected'],
                ],
            ];

            // Detalle de manifiestos rechazados por almacén desconocido
            if (!empty($summary['manifiestos_rechazados'])) {
                $response['success'] = false;
                $response['message'] = 'Uno o más manifiestos fueron rechazados por contener almacenes no registrados en el sistema.';
                $response['motivo']  = 'ALMACENES_DESCONOCIDOS';
                $response['manifiestos_rechazados'] = array_map(
                    fn ($r) => [
                        'manifiesto'             => $r['manifiesto'],
                        'total_facturas'         => $r['total_facturas'],
                        'almacenes_desconocidos' => array_map(
                            fn ($codigo, $facturas) => [
                                'almacen'  => $codigo,
                                'facturas' => $facturas,
                                'cantidad' => count($facturas),
                            ],
                            array_keys($r['almacenes_desconocidos']),
                            array_values($r['almacenes_desconocidos'])
                        ),
                    ],
                    $summary['manifiestos_rechazados']
                );
            }

            if (!empty($summary['warnings'])) {
                $response['advertencias'] = $summary['warnings'];
            }

            if (!empty($summary['errors'])) {
                $response['rechazadas_detalle'] = $summary['errors'];
            }

            $statusCode = !empty($summary['manifiestos_rechazados']) ? 422 : 200;

            return new JsonResponse($response, $statusCode);

        } catch (\Throwable $e) {
            $importRecord->markAsFailed($e->getMessage());

            Log::error('API Jaremar: error procesando batch.', [
                'batch_uuid' => $importRecord->batch_uuid,
                'ip'         => $request->ip(),
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success'    => false,
                'message'    => 'Error interno al procesar las facturas. El equipo técnico ha sido notificado.',
                'batch_uuid' => $importRecord->batch_uuid,
            ], 500);
        }
    }

    /**
     * GET /api/v1/manifiestos/{numero}/estado
     */
    public function estado(string $numero): JsonResponse
    {
        $manifest = Manifest::where('number', $numero)->first();

        if (!$manifest) {
            return new JsonResponse([
                'success' => false,
                'message' => "El manifiesto #{$numero} no existe en el sistema.",
            ], 404);
        }

        return new JsonResponse([
            'success'    => true,
            'manifiesto' => $numero,
            'estado'     => $manifest->status,
            'resumen'    => [
                'total_facturas' => $manifest->invoices_count,
                'total_importe'  => (float) $manifest->total_invoices,
                'fecha_ingreso'  => $manifest->created_at->toDateTimeString(),
            ],
        ], 200);
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
                $cuerpo = "Jaremar intentó agregar {$rechazado['total_facturas']} factura(s) al manifiesto #{$manifiesto} " .
                          "el día {$rechazado['fecha_intento']}, pero ese manifiesto fue creado el {$rechazado['fecha_original']} " .
                          "y ya no acepta facturas nuevas. Se rechazó el batch completo.";
            } else {
                $total   = $rechazado['total_facturas'];
                $codigos = implode(', ', array_keys($rechazado['almacenes_desconocidos']));
                $titulo  = "Manifiesto #{$manifiesto} rechazado — almacén desconocido";
                $cuerpo  = "El manifiesto #{$manifiesto} fue rechazado. " .
                           "Contiene {$total} factura(s) con almacén(es) no registrado(s): {$codigos}. " .
                           "Jaremar debe registrar el almacén o corregir el código y reenviar.";
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
            'batch_uuid'         => $batchUuid,
            'manifiestos'        => array_column($manifistosRechazados, 'manifiesto'),
            'admins_notificados' => $admins->pluck('name')->toArray(),
        ]);
    }
}