<?php

namespace App\Services;

use App\Jobs\RecalculateManifestTotalsJob;
use App\Models\Invoice;
use App\Models\InvoiceReturn;
use App\Models\Manifest;
use App\Models\ReturnLine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnService
{
    public function createReturn(array $data): InvoiceReturn
    {
        // Mantenemos la transacción corta: solo escrituras críticas.
        // El recálculo de totales y la invalidación de cache van FUERA
        // para no bloquear la transacción con queries pesados.
        $return = DB::transaction(function () use ($data) {
            // ── Race condition guard ─────────────────────────────────────
            // lockForUpdate() adquiere un bloqueo a nivel de fila en PostgreSQL.
            // Si dos usuarios intentan devolver la misma factura al mismo tiempo,
            // el segundo request esperará hasta que el primero confirme (commit)
            // o aborte (rollback). Esto garantiza que las cantidades devueltas
            // calculadas dentro de esta transacción siempre son correctas.
            $invoice = Invoice::with(['lines', 'manifest'])
                ->lockForUpdate()
                ->findOrFail($data['invoice_id']);

            // ── Closed manifest guard (server-side) ──────────────────────
            // La UI oculta el botón "Devolver" en manifiestos cerrados, pero
            // esta validación es la última línea de defensa contra requests
            // directos o condiciones de carrera con el cierre del manifiesto.
            if ($invoice->manifest->isClosed()) {
                throw ValidationException::withMessages([
                    'invoice_id' => 'No se puede registrar una devolución: el manifiesto está cerrado.',
                ]);
            }

            $linesData = $data['lines'] ?? [];

            // ── Server-side quantity validation ──────────────────────────
            // El frontend limita la cantidad con `available_quantity`, pero
            // ese valor se calculó al abrir el modal. Si entre ese momento y
            // el submit otra sesión registró otra devolución, los datos del
            // frontend ya son obsoletos. Recalculamos aquí dentro de la
            // transacción, con el lock activo, para tener la fuente de verdad.
            $lineIds        = $invoice->lines->pluck('id')->toArray();
            $returnedByLine = $this->getReturnedQuantitiesForLines($lineIds);

            $linesByOriginalId = $invoice->lines->keyBy('id');
            $validationErrors  = [];

            foreach ($linesData as $lineData) {
                $lineId = $lineData['invoice_line_id'] ?? null;
                $boxes  = (float)($lineData['quantity_box'] ?? 0);
                $units  = (float)($lineData['quantity']     ?? 0);

                // Saltar líneas sin cantidad (ni cajas ni unidades sueltas)
                if (!$lineId || ($boxes <= 0 && $units <= 0)) {
                    continue;
                }

                $originalLine = $linesByOriginalId[$lineId] ?? null;
                if (!$originalLine) {
                    continue;
                }

                $alreadyReturned = (float)($returnedByLine[$lineId] ?? 0);
                $available       = max(0, (float)$originalLine->quantity_fractions - $alreadyReturned);

                // Total solicitado en fracciones/unidades: cajas × factor + unidades sueltas
                $convFactor     = max(1, (float)($originalLine->conversion_factor ?? 1));
                $requestedTotal = ($boxes * $convFactor) + $units;

                if ($requestedTotal > $available + 0.001) {
                    $validationErrors["lines.{$lineId}.quantity"] =
                        "La cantidad solicitada ({$requestedTotal} uds.) supera la disponible ({$available}) para el producto {$originalLine->product_description}.";
                }
            }

            if (!empty($validationErrors)) {
                throw ValidationException::withMessages($validationErrors);
            }

            // Total calculado server-side: suma de (cajas×factor + unidades) × precio_unitario
            // por cada línea con cantidad > 0. No depende de line_total del frontend.
            $total = 0.0;
            foreach ($linesData as $lineData) {
                $lid   = $lineData['invoice_line_id'] ?? null;
                $b     = (float)($lineData['quantity_box'] ?? 0);
                $u     = (float)($lineData['quantity']     ?? 0);
                if (!$lid || ($b <= 0 && $u <= 0)) continue;
                $ol    = $linesByOriginalId[$lid] ?? null;
                $cf    = max(1, (float)($ol?->conversion_factor ?? 1));
                $up    = (float)($ol?->price_min_sale ?: $ol?->price ?? 0);
                $total += round(($b * $cf + $u) * $up, 2);
            }

            $pendingAmount = $this->getPendingAmount($invoice);
            $type = (abs($total - $pendingAmount) < 0.01) ? 'total' : 'partial';

            $now = now();

            $return = InvoiceReturn::create([
                'manifest_id'      => $invoice->manifest_id,
                'invoice_id'       => $invoice->id,
                'return_reason_id' => $data['return_reason_id'],
                'warehouse_id'     => $invoice->warehouse_id,
                'type'             => $type,
                'status'           => 'approved',
                'client_id'        => $invoice->client_id,
                'client_name'      => $invoice->client_name,
                'return_date'      => $data['return_date'],
                'processed_date'   => $now->toDateString(),
                'processed_time'   => $now->format('H:i:s'),
                'total'            => $total,
                'created_by'       => $data['created_by'],
                'manifest_number'  => $invoice->manifest->number,
            ]);

            foreach ($linesData as $lineData) {
                $lineId = $lineData['invoice_line_id'] ?? null;
                $boxes  = (float)($lineData['quantity_box'] ?? 0);
                $units  = (float)($lineData['quantity']     ?? 0);

                // Solo crear línea si hay algo que devolver
                if ($boxes <= 0 && $units <= 0) {
                    continue;
                }

                // Calcular line_total server-side para evitar depender del valor
                // enviado por el frontend (campos disabled en Repeaters de Filament v4
                // no siempre envían su valor reactivo correctamente al submit).
                // Fuente de verdad: invoice_lines de la BD.
                $originalLine = $lineId ? ($linesByOriginalId[$lineId] ?? null) : null;
                $convFactor   = max(1, (float)($originalLine?->conversion_factor ?? 1));
                $unitPrice    = (float)($originalLine?->price_min_sale ?: $originalLine?->price ?? 0);
                $lineTotal    = round(($boxes * $convFactor + $units) * $unitPrice, 2);

                ReturnLine::create([
                    'return_id'           => $return->id,
                    'invoice_line_id'     => $lineId,
                    'line_number'         => $lineData['line_number'],
                    'product_id'          => $lineData['product_id'],
                    'product_description' => $lineData['product_description'],
                    'quantity_box'        => $boxes,
                    'quantity'            => $units,
                    'line_total'          => $lineTotal,
                ]);
            }

            $this->updateInvoiceStatus($invoice);

            return $return;
        });

        // ── Post-transacción ─────────────────────────────────────────────
        // Recalcular totales de forma sincrónica para garantizar consistencia
        // inmediata. Si hay un worker de cola activo, también despachamos el
        // job para cubrir cualquier operación concurrente que pudiera haberse
        // solapado con la transacción.
        $this->recalculateManifestTotals($return->manifest_id);

        // Invalidar cache de Jaremar para TODAS las páginas de hoy.
        $this->invalidateDevolucionesCache(now()->toDateString());

        return $return;
    }

    public function approveReturn(InvoiceReturn $return, int $reviewedBy): void
    {
        // Proteger manifiestos cerrados. Un manifiesto puede cerrarse mientras
        // una devolución pendiente espera aprobación. Si se aprobara después
        // del cierre, los totales del manifiesto quedarían corruptos.
        if ($return->manifest->isClosed()) {
            throw new \RuntimeException(
                'No se puede aprobar una devolución de un manifiesto cerrado. ' .
                'Reabre el manifiesto antes de aprobar.'
            );
        }

        $now = now();

        DB::transaction(function () use ($return, $reviewedBy, $now) {
            $return->update([
                'status'         => 'approved',
                'reviewed_by'    => $reviewedBy,
                'reviewed_at'    => $now,
                'processed_date' => $now->toDateString(),
                'processed_time' => $now->format('H:i:s'),
            ]);

            $this->updateInvoiceStatus($return->invoice);
        });

        // Post-transacción: recalcular + invalidar cache de Jaremar.
        // Al aprobar, la devolución pasa a ser visible en la API con
        // processed_date = hoy, así que invalidamos el cache del día.
        $this->recalculateManifestTotals($return->manifest_id);
        $this->invalidateDevolucionesCache($now->toDateString());
    }

    public function rejectReturn(InvoiceReturn $return, int $reviewedBy, string $reason): void
    {
        // Mismo guard que approveReturn: un manifiesto cerrado no debe
        // permitir cambios de status en sus devoluciones.
        if ($return->manifest->isClosed()) {
            throw new \RuntimeException(
                'No se puede rechazar una devolución de un manifiesto cerrado.'
            );
        }

        DB::transaction(function () use ($return, $reviewedBy, $reason) {
            $return->update([
                'status'           => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by'      => $reviewedBy,
                'reviewed_at'      => now(),
            ]);

            $this->updateInvoiceStatus($return->invoice);
        });

        // Las devoluciones rechazadas no aparecen en la API de Jaremar
        // (filtro aprobadas únicamente), por lo que no es necesario
        // invalidar el cache. Sí recalculamos los totales del manifiesto
        // porque returns_count cambia.
        $this->recalculateManifestTotals($return->manifest_id);
    }

    /**
     * Monto pendiente de devolver = total factura - (aprobadas + pendientes)
     */
    public function getPendingAmount(Invoice $invoice): float
    {
        $totalReturned = $invoice->returns()
            ->whereIn('status', ['approved', 'pending'])
            ->sum('total');

        return max(0, (float)$invoice->total - (float)$totalReturned);
    }

    /**
     * Verifica si la factura tiene al menos una línea con cantidad disponible.
     * Esta es la fuente de verdad para mostrar/ocultar el botón "Devolver".
     *
     * Carga todas las cantidades devueltas en UNA sola query (bulk)
     * para evitar N+1 cuando la factura tiene muchas líneas.
     */
    public function hasAvailableLines(Invoice $invoice): bool
    {
        $invoice->loadMissing('lines');

        if ($invoice->lines->isEmpty()) {
            return false;
        }

        $returnedByLine = $this->getReturnedQuantitiesForLines(
            $invoice->lines->pluck('id')->toArray()
        );

        foreach ($invoice->lines as $line) {
            $returned  = (float)($returnedByLine[$line->id] ?? 0);
            $available = (float)$line->quantity_fractions - $returned;
            if ($available > 0.001) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cantidad ya devuelta (aprobada + pendiente) de una línea específica.
     *
     * Usar getReturnedQuantitiesForLines() si se necesita consultar
     * múltiples líneas a la vez (evita N+1).
     */
    public function getReturnedQuantity(int $invoiceLineId): float
    {
        $result = ReturnLine::query()
            ->where('return_lines.invoice_line_id', $invoiceLineId)
            ->whereHas('return', fn($q) => $q->whereIn('status', ['approved', 'pending']))
            ->join('invoice_lines', 'return_lines.invoice_line_id', '=', 'invoice_lines.id')
            ->selectRaw(
                'COALESCE(SUM(' .
                    'return_lines.quantity_box * GREATEST(COALESCE(invoice_lines.conversion_factor, 1), 1) ' .
                    '+ return_lines.quantity' .
                '), 0) AS total_returned'
            )
            ->value('total_returned');

        return (float) $result;
    }

    /**
     * Devuelve un mapa [invoice_line_id => cantidad_devuelta] para un conjunto
     * de líneas. Una sola query independientemente del número de líneas.
     *
     * @param  int[]  $lineIds
     * @return array<int, float>
     */
    public function getReturnedQuantitiesForLines(array $lineIds): array
    {
        if (empty($lineIds)) {
            return [];
        }

        // Sumamos en fracciones: (cajas × factor_conversión) + unidades_sueltas.
        // JOIN con invoice_lines para obtener el factor de conversión de cada línea.
        return ReturnLine::query()
            ->whereIn('return_lines.invoice_line_id', $lineIds)
            ->whereHas('return', fn($q) => $q->whereIn('status', ['approved', 'pending']))
            ->join('invoice_lines', 'return_lines.invoice_line_id', '=', 'invoice_lines.id')
            ->selectRaw(
                'return_lines.invoice_line_id, ' .
                'COALESCE(SUM(' .
                    'return_lines.quantity_box * GREATEST(COALESCE(invoice_lines.conversion_factor, 1), 1) ' .
                    '+ return_lines.quantity' .
                '), 0) AS total_returned'
            )
            ->groupBy('return_lines.invoice_line_id')
            ->pluck('total_returned', 'invoice_line_id')
            ->map(fn($v) => (float) $v)
            ->toArray();
    }

    /**
     * Igual que getReturnedQuantitiesForLines() pero excluye las líneas de
     * una devolución específica del cálculo.
     *
     * Usado en el flujo de edición: al pre-cargar el formulario de edición,
     * la cantidad "disponible" de cada línea debe calcularse sin contar lo
     * que ya devolvió ESTA devolución (para que el usuario pueda ver y
     * modificar sus propias cantidades sin que el máx quede en 0).
     *
     * @param  int[]  $lineIds
     * @param  int    $excludeReturnId  ID de la devolución a excluir
     * @return array<int, float>
     */
    public function getReturnedQuantitiesForLinesExcluding(array $lineIds, int $excludeReturnId): array
    {
        if (empty($lineIds)) {
            return [];
        }

        return ReturnLine::query()
            ->whereIn('return_lines.invoice_line_id', $lineIds)
            ->where('return_lines.return_id', '!=', $excludeReturnId)
            ->whereHas('return', fn($q) => $q->whereIn('status', ['approved', 'pending']))
            ->join('invoice_lines', 'return_lines.invoice_line_id', '=', 'invoice_lines.id')
            ->selectRaw(
                'return_lines.invoice_line_id, ' .
                'COALESCE(SUM(' .
                    'return_lines.quantity_box * GREATEST(COALESCE(invoice_lines.conversion_factor, 1), 1) ' .
                    '+ return_lines.quantity' .
                '), 0) AS total_returned'
            )
            ->groupBy('return_lines.invoice_line_id')
            ->pluck('total_returned', 'invoice_line_id')
            ->map(fn($v) => (float) $v)
            ->toArray();
    }

    /**
     * Invalida el cache de devoluciones para la fecha dada.
     *
     * Estrategia de versiones: al incrementar el contador de versión,
     * cualquier clave cacheada que incluya la versión anterior queda
     * obsoleta automáticamente, sin importar cuántas páginas existan.
     * Las entradas antiguas expiran naturalmente según su TTL original.
     */
    public function invalidateDevolucionesCache(string $date): void
    {
        Cache::increment("devoluciones:version:{$date}");
    }

    /**
     * API pública para recalcular el status de una factura desde fuera del servicio
     * (p.ej. desde InvoiceReturnObserver cuando se elimina una devolución).
     */
    public function refreshInvoiceStatus(Invoice $invoice): void
    {
        $this->updateInvoiceStatus($invoice);
    }

    /**
     * Recalcula los totales del manifiesto de forma sincrónica y además
     * encola el job para cubrir posibles carreras concurrentes.
     * Si no hay worker activo (ej. desarrollo local sin Redis), el recálculo
     * sincrónico garantiza que los datos se reflejen de inmediato en pantalla.
     */
    public function recalculateManifestTotals(int $manifestId): void
    {
        $manifest = Manifest::find($manifestId);
        $manifest?->recalculateTotals();

        // También despachamos el job por si ocurrió alguna operación concurrente
        // entre que terminó nuestra transacción y el momento del recálculo.
        RecalculateManifestTotalsJob::dispatch($manifestId);
    }

    protected function updateInvoiceStatus(Invoice $invoice): void
    {
        $invoice->refresh();

        $totalApproved = (float) $invoice->returns()
            ->where('status', 'approved')
            ->sum('total');

        $hasPending = $invoice->returns()
            ->where('status', 'pending')
            ->exists();

        $hasAvailable = $this->hasAvailableLines($invoice);

        if ($totalApproved <= 0 && !$hasPending) {
            $invoice->update(['status' => 'imported']);

        } elseif (!$hasAvailable && !$hasPending) {
            $invoice->update(['status' => 'returned']);

        } else {
            $invoice->update(['status' => 'partial_return']);
        }
    }
}