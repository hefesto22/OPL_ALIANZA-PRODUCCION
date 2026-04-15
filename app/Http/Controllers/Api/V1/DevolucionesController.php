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
     * GET v1/devoluciones/listar
     *
     * Jaremar llama este endpoint con:
     *   Header ApiKey: <clave>
     *   Header Fecha:  17/12/2025  (dd/MM/yyyy)
     *   Query  pagina: 1           (opcional, default 1)
     *
     * Devuelve todas las devoluciones aprobadas cuyo
     * processed_date coincida con la fecha enviada.
     *
     * Cache:
     *   - Fechas pasadas: 60 minutos (no cambian)
     *   - Fecha de hoy:   5 minutos  (pueden llegar nuevas devoluciones)
     *
     * Límite: 1000 registros por página (protección ante volúmenes anómalos)
     */
    public function listar(Request $request): JsonResponse
    {
        // ── 1. Validar header Fecha ───────────────────────────
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

        try {
            // Carbon::createFromFormat es permisivo: '99/99/9999' no tira
            // exception, hace overflow a una fecha "válida" cualquiera. Para
            // detectar ese caso re-formateamos el parseo y lo comparamos con
            // el string original — si no coincide, la fecha era basura.
            $parsed = Carbon::createFromFormat('d/m/Y', $fechaHeader);
            if ($parsed === false || $parsed->format('d/m/Y') !== $fechaHeader) {
                throw new \InvalidArgumentException("Fecha overflow: {$fechaHeader}");
            }
            $fecha = $parsed->toDateString();
        } catch (\Exception $e) {
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

        // ── 2. Paginación opcional ────────────────────────────
        // Jaremar no la usa hoy, pero queda disponible sin romper compatibilidad.
        // Si no mandan ?pagina=, se asume página 1.
        $pagina = max(1, (int) $request->query('pagina', 1));
        $porPagina = 1000;

        // ── 3. Determinar TTL de cache según la fecha ─────────
        // Fechas pasadas nunca cambian → cache largo.
        // La fecha de hoy puede tener devoluciones nuevas → cache corto.
        $esHoy = $fecha === now()->toDateString();
        $ttl = $esHoy ? 300 : 3600; // 5 min hoy, 60 min fechas pasadas

        // ── 4. Cache por fecha + versión + página ─────────────
        // La versión se incrementa cada vez que se crea o aprueba una
        // devolución para ese día (ver ReturnService::invalidateDevolucionesCache).
        // Al cambiar la versión, TODAS las páginas cacheadas de esa fecha
        // quedan obsoletas automáticamente sin necesidad de conocer cuántas
        // páginas existen. Las entradas antiguas expiran según su TTL.
        $version = (int) Cache::get("devoluciones:version:{$fecha}", 1);
        $cacheKey = "devoluciones:listar:{$fecha}:v{$version}:pagina:{$pagina}";

        $resultado = Cache::remember($cacheKey, $ttl, function () use ($fecha, $pagina, $porPagina) {
            $devoluciones = InvoiceReturn::with([
                'invoice:id,invoice_number,client_id,client_name',
                'manifest:id,number',
                'warehouse:id,code',
                'returnReason:id,jaremar_id,code,description',
                'lines:id,return_id,line_number,product_id,product_description,quantity_box,quantity,line_total',
            ])
                ->approved()
                ->whereDate('processed_date', $fecha)
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
                    'total' => (float) $devolucion->total,
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
                            'cantidad' => (float) ($linea->quantity_box > 0
                                                ? $linea->quantity_box
                                                : $linea->quantity),
                            'numeroLinea' => (string) $linea->line_number,
                            'lineTotal' => (float) $linea->line_total,
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
                'fecha' => $fecha,
                'pagina' => $pagina,
                'total' => count($resultado),
                'desde_cache' => Cache::has($cacheKey),
                'resultado' => 'ok',
            ])
            ->log('Jaremar consultó devoluciones');

        return response()->json($resultado);
    }
}
