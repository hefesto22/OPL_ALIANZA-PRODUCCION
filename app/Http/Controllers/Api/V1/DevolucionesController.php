<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InvoiceReturn;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DevolucionesController extends Controller
{
    /**
     * Sentinela para emitir un valor como NÚMERO JSON con escala fija de 6
     * decimales (ej. 255 → 255.000000). Usa caracteres que no aparecen en
     * los datos para que el des-entrecomillado por regex sea inequívoco.
     */
    private const NUM_SENTINEL = '@@N6@@';

    /**
     * Formatea un monto/cantidad a string con 6 decimales fijos y lo marca
     * con el sentinela para que listar() lo convierta luego en un número JSON
     * sin comillas, replicando el contrato exacto del ERP de Jaremar.
     *
     * json_encode descarta los ceros de cola en floats (255.00 → 255), por lo
     * que no basta con castear. Aquí solo formateamos para presentación —no hay
     * aritmética— sobre valores ya casteados como decimal:2/decimal:4, así que
     * no hay riesgo de precisión a esta escala.
     */
    private function numero6(int|float|string $valor): string
    {
        return self::NUM_SENTINEL.number_format((float) $valor, 6, '.', '').self::NUM_SENTINEL;
    }

    /**
     * Parsea una fecha estricta dd/MM/yyyy a formato Y-m-d.
     *
     * Carbon::createFromFormat es permisivo: '99/99/9999' no lanza excepción,
     * hace overflow a una fecha cualquiera. Para detectarlo re-formateamos el
     * parseo y lo comparamos con el string original — si no coincide, era basura.
     *
     * @return string|null  Y-m-d si es válida; null si el formato es inválido.
     */
    private function parsearFecha(string $valor): ?string
    {
        try {
            $parsed = Carbon::createFromFormat('d/m/Y', $valor);
            if ($parsed === false || $parsed->format('d/m/Y') !== $valor) {
                return null;
            }

            return $parsed->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * GET v1/devoluciones/listar
     *
     * Jaremar llama este endpoint con:
     *   Header ApiKey:     <clave>
     *   Header Fecha:      17/12/2025  (dd/MM/yyyy) — inicio del rango, requerido
     *   Header FechaHasta: 20/12/2025  (dd/MM/yyyy) — fin del rango, OPCIONAL
     *   Query  pagina:     1           (opcional, default 1)
     *
     * Devuelve todas las devoluciones aprobadas cuyo processed_date cae dentro
     * del rango [Fecha, FechaHasta], AMBOS inclusive. Sin FechaHasta el rango es
     * de un solo día (Fecha == FechaHasta), idéntico al comportamiento histórico.
     *
     * Tope de rango: config('api.devoluciones_max_dias_rango') días (default 31).
     * Un rango mayor se rechaza con 422 para proteger query, caché y respuesta.
     *
     * Cache:
     *   - Rango que incluye hoy:   5 minutos  (pueden llegar nuevas devoluciones)
     *   - Rango totalmente pasado: 60 minutos (no cambia)
     *   La clave usa una versión COMPUESTA de los contadores por-día del rango
     *   (devoluciones:version:{día}); cuando cambia cualquier día, la caché del
     *   rango se invalida sola sin lógica de invalidación nueva.
     *
     * Límite: 1000 registros por página. Para rangos grandes, iterar ?pagina
     * hasta recibir [].
     */
    public function listar(Request $request): JsonResponse
    {
        // ── 1. Header Fecha (inicio del rango, requerido) ─────
        $fechaHeader = $request->header('Fecha');

        if (empty($fechaHeader)) {
            activity('api')
                ->withProperties([
                    'endpoint' => 'GET v1/devoluciones/listar',
                    'ip' => $request->ip(),
                    'fecha' => null,
                    'resultado' => 'error_fecha_faltante',
                ])
                ->log('Jaremar consultó devoluciones sin header Fecha');

            return response()->json([
                'success' => false,
                'message' => 'El header Fecha es obligatorio. Formato esperado: dd/MM/yyyy. Ejemplo: 17/12/2025',
            ], 422);
        }

        $desde = $this->parsearFecha($fechaHeader);
        if ($desde === null) {
            activity('api')
                ->withProperties([
                    'endpoint' => 'GET v1/devoluciones/listar',
                    'ip' => $request->ip(),
                    'fecha' => $fechaHeader,
                    'resultado' => 'error_fecha_invalida',
                ])
                ->log('Jaremar consultó devoluciones con fecha inválida');

            return response()->json([
                'success' => false,
                'message' => 'Formato de fecha inválido. Se esperaba dd/MM/yyyy. Ejemplo: 17/12/2025',
            ], 422);
        }

        // ── 1b. Header FechaHasta (fin del rango, opcional) ───
        // Sin FechaHasta → rango de un solo día (compatibilidad con la
        // integración actual de Jaremar, que solo manda Fecha).
        $fechaHastaHeader = $request->header('FechaHasta');
        if (empty($fechaHastaHeader)) {
            $hasta = $desde;
        } else {
            $hasta = $this->parsearFecha($fechaHastaHeader);
            if ($hasta === null) {
                activity('api')
                    ->withProperties([
                        'endpoint' => 'GET v1/devoluciones/listar',
                        'ip' => $request->ip(),
                        'fecha' => $fechaHeader,
                        'fecha_hasta' => $fechaHastaHeader,
                        'resultado' => 'error_fecha_hasta_invalida',
                    ])
                    ->log('Jaremar consultó devoluciones con FechaHasta inválida');

                return response()->json([
                    'success' => false,
                    'message' => 'Formato de FechaHasta inválido. Se esperaba dd/MM/yyyy. Ejemplo: 20/12/2025',
                ], 422);
            }
        }

        // ── 1c. Coherencia y tope del rango ───────────────────
        if ($hasta < $desde) {
            activity('api')
                ->withProperties([
                    'endpoint' => 'GET v1/devoluciones/listar',
                    'ip' => $request->ip(),
                    'fecha' => $desde,
                    'fecha_hasta' => $hasta,
                    'resultado' => 'error_rango_invertido',
                ])
                ->log('Jaremar consultó devoluciones con FechaHasta anterior a Fecha');

            return response()->json([
                'success' => false,
                'message' => 'FechaHasta no puede ser anterior a Fecha.',
            ], 422);
        }

        $maxDias = (int) config('api.devoluciones_max_dias_rango', 31);
        $diasRango = Carbon::parse($desde)->diffInDays(Carbon::parse($hasta)) + 1; // inclusive

        if ($maxDias > 0 && $diasRango > $maxDias) {
            activity('api')
                ->withProperties([
                    'endpoint' => 'GET v1/devoluciones/listar',
                    'ip' => $request->ip(),
                    'fecha' => $desde,
                    'fecha_hasta' => $hasta,
                    'dias' => $diasRango,
                    'resultado' => 'error_rango_excedido',
                ])
                ->log('Jaremar consultó un rango de devoluciones demasiado amplio');

            return response()->json([
                'success' => false,
                'message' => "El rango solicitado ({$diasRango} días) supera el máximo permitido de {$maxDias} días. Acote el período (Fecha / FechaHasta) y reintente.",
            ], 422);
        }

        // ── 2. Paginación opcional ────────────────────────────
        // Para rangos grandes, Jaremar itera ?pagina hasta recibir [].
        $pagina = max(1, (int) $request->query('pagina', 1));
        $porPagina = 1000;

        // ── 3. TTL según si el rango incluye hoy ──────────────
        // processed_date nunca es futuro; "incluye hoy" = el rango llega a hoy.
        $incluyeHoy = $hasta >= now()->toDateString();
        $ttl = $incluyeHoy ? 300 : 3600; // 5 min con hoy, 60 min totalmente pasado

        // ── 4. Cache con versión COMPUESTA del rango ──────────
        // Cada día tiene su contador devoluciones:version:{día}, que
        // ReturnService incrementa al crear/aprobar/cancelar una devolución
        // de ese día. La firma combina los contadores de TODO el rango, así
        // que un cambio en cualquier día altera la clave y la caché del rango
        // queda obsoleta sola — sin lógica de invalidación nueva.
        $dias = [];
        $cursor = Carbon::parse($desde);
        $fin = Carbon::parse($hasta);
        while ($cursor->lte($fin)) {
            $dias[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $versionKeys = array_map(fn ($dia) => "devoluciones:version:{$dia}", $dias);
        $versiones = Cache::many($versionKeys); // 1 round-trip a Redis

        $firma = '';
        foreach ($dias as $dia) {
            $firma .= $dia.':'.((int) ($versiones["devoluciones:version:{$dia}"] ?? 1)).'|';
        }
        $version = md5($firma);

        $cacheKey = "devoluciones:listar:{$desde}:{$hasta}:v{$version}:pagina:{$pagina}";

        $resultado = Cache::remember($cacheKey, $ttl, function () use ($desde, $hasta, $pagina, $porPagina) {
            $devoluciones = InvoiceReturn::with([
                'invoice:id,invoice_number,client_id,client_name',
                'manifest:id,number',
                'warehouse:id,code',
                'returnReason:id,jaremar_id,code,description',
                'lines:id,return_id,line_number,product_id,product_description,quantity_box,quantity,line_total',
            ])
                ->approved()
                ->whereBetween('processed_date', [$desde, $hasta])
                ->orderBy('id')
                ->limit($porPagina)
                ->offset(($pagina - 1) * $porPagina)
                ->get();

            return $devoluciones->map(function (InvoiceReturn $devolucion) {
                return [
                    'devolucion' => (string) $devolucion->id,
                    'factura' => $devolucion->invoice->invoice_number ?? '',
                    'clienteid' => $devolucion->invoice->client_id ?? '',
                    'cliente' => $devolucion->invoice->client_name ?? '',
                    'fecha' => $devolucion->return_date
                                            ? $devolucion->return_date->format('Y-m-d\TH:i:s')
                                            : null,
                    'total' => $this->numero6($devolucion->total),
                    'almacen' => $devolucion->warehouse->code ?? '',
                    'idConcepto' => (string) ($devolucion->returnReason->jaremar_id
                                            ?: $devolucion->returnReason->code
                                            ?? ''),
                    'concepto' => $devolucion->returnReason->description ?? '',
                    'numeroManifiesto' => (string) ($devolucion->manifest->number ?? ''),
                    'fechaProcesado' => $devolucion->processed_date
                                            ? $devolucion->processed_date->format('Y-m-d\TH:i:s')
                                            : null,
                    'horaProcesado' => $devolucion->processed_time
                                            ? (string) $devolucion->processed_time
                                            : null,
                    'lineasDevolucion' => $devolucion->lines->map(function ($linea) {
                        return [
                            'productoId' => $linea->product_id,
                            'producto' => $linea->product_description,
                            // CJ products: quantity=0, quantity_box>0 → devolver cajas
                            // UN products: quantity_box=0, quantity>0 → devolver unidades
                            'cantidad' => $this->numero6($linea->quantity_box > 0
                                                ? $linea->quantity_box
                                                : $linea->quantity),
                            'numeroLinea' => (string) $linea->line_number,
                            'lineTotal' => $this->numero6($linea->line_total),
                        ];
                    })->values()->all(),
                ];
            })->values()->all();
        });

        // ── 5. Registrar la llamada exitosa ───────────────────
        // Se registra FUERA del cache para que quede constancia
        // de cada llamada real de Jaremar, no solo las que van a BD.
        activity('api')
            ->withProperties([
                'endpoint' => 'GET v1/devoluciones/listar',
                'ip' => $request->ip(),
                'fecha' => $desde,
                'fecha_hasta' => $hasta,
                'dias' => $diasRango,
                'pagina' => $pagina,
                'total' => count($resultado),
                'desde_cache' => Cache::has($cacheKey),
                'resultado' => 'ok',
            ])
            ->log('Jaremar consultó devoluciones');

        // ── 6. Serialización con decimales de escala fija ─────
        // Emitimos total/cantidad/lineTotal como números JSON con 6 decimales
        // (255 → 255.000000) para clonar el contrato del ERP de Jaremar.
        // json_encode entrecomilla los sentinelas; el preg_replace los quita
        // dejando un literal numérico válido. El patrón exige exactamente
        // 6 decimales, así que no toca ningún otro string de la respuesta.
        $json = json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = preg_replace(
            '/"'.preg_quote(self::NUM_SENTINEL, '/').'(-?\d+\.\d{6})'.preg_quote(self::NUM_SENTINEL, '/').'"/',
            '$1',
            $json
        );

        // fromJsonString conserva el string tal cual (no re-codifica), así los
        // decimales de escala fija no se pierden, y mantiene el tipo JsonResponse.
        return JsonResponse::fromJsonString($json, 200);
    }
}
