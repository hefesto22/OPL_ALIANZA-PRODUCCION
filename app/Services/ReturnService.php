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
            $lineIds = $invoice->lines->pluck('id')->toArray();
            $returnedByLine = $this->getReturnedQuantitiesForLines($lineIds);

            $linesByOriginalId = $invoice->lines->keyBy('id');
            $validationErrors = [];

            foreach ($linesData as $lineData) {
                $lineId = $lineData['invoice_line_id'] ?? null;
                $boxes = (float) ($lineData['quantity_box'] ?? 0);
                $units = (float) ($lineData['quantity'] ?? 0);

                // Saltar líneas sin cantidad (ni cajas ni unidades sueltas)
                if (! $lineId || ($boxes <= 0 && $units <= 0)) {
                    continue;
                }

                $originalLine = $linesByOriginalId[$lineId] ?? null;
                if (! $originalLine) {
                    continue;
                }

                $alreadyReturned = (float) ($returnedByLine[$lineId] ?? 0);
                $available = max(0, (float) $originalLine->quantity_fractions - $alreadyReturned);

                // Total solicitado en fracciones/unidades: cajas × factor + unidades sueltas
                $convFactor = max(1, (float) ($originalLine->conversion_factor ?? 1));
                $requestedTotal = ($boxes * $convFactor) + $units;

                if ($requestedTotal > $available + 0.001) {
                    $validationErrors["lines.{$lineId}.quantity"] =
                        "La cantidad solicitada ({$requestedTotal} uds.) supera la disponible ({$available}) para el producto {$originalLine->product_description}.";
                }
            }

            if (! empty($validationErrors)) {
                throw ValidationException::withMessages($validationErrors);
            }

            // Total calculado server-side: suma de (cajas×factor + unidades) × precio_unitario
            // por cada línea con cantidad > 0. No depende de line_total del frontend.
            $total = 0.0;
            foreach ($linesData as $lineData) {
                $lid = $lineData['invoice_line_id'] ?? null;
                $b = (float) ($lineData['quantity_box'] ?? 0);
                $u = (float) ($lineData['quantity'] ?? 0);
                if (! $lid || ($b <= 0 && $u <= 0)) {
                    continue;
                }
                $ol = $linesByOriginalId[$lid] ?? null;
                $cf = max(1, (float) ($ol?->conversion_factor ?? 1));
                $up = (float) ($ol?->price_min_sale ?: $ol?->price ?? 0);
                $total += round(($b * $cf + $u) * $up, 2);
            }

            $pendingAmount = $this->getPendingAmount($invoice);
            $type = (abs($total - $pendingAmount) < 0.01) ? 'total' : 'partial';

            $now = now();

            $return = InvoiceReturn::create([
                'manifest_id' => $invoice->manifest_id,
                'invoice_id' => $invoice->id,
                'return_reason_id' => $data['return_reason_id'],
                'warehouse_id' => $invoice->warehouse_id,
                'type' => $type,
                'status' => 'approved',
                'client_id' => $invoice->client_id,
                'client_name' => $invoice->client_name,
                'return_date' => $data['return_date'],
                'processed_date' => $now->toDateString(),
                'processed_time' => $now->format('H:i:s'),
                'total' => $total,
                'created_by' => $data['created_by'],
                'manifest_number' => $invoice->manifest->number,
            ]);

            foreach ($linesData as $lineData) {
                $lineId = $lineData['invoice_line_id'] ?? null;
                $boxes = (float) ($lineData['quantity_box'] ?? 0);
                $units = (float) ($lineData['quantity'] ?? 0);

                // Solo crear línea si hay algo que devolver
                if ($boxes <= 0 && $units <= 0) {
                    continue;
                }

                // Calcular line_total server-side para evitar depender del valor
                // enviado por el frontend (campos disabled en Repeaters de Filament v4
                // no siempre envían su valor reactivo correctamente al submit).
                // Fuente de verdad: invoice_lines de la BD.
                $originalLine = $lineId ? ($linesByOriginalId[$lineId] ?? null) : null;
                $convFactor = max(1, (float) ($originalLine?->conversion_factor ?? 1));
                $unitPrice = (float) ($originalLine?->price_min_sale ?: $originalLine?->price ?? 0);
                $lineTotal = round(($boxes * $convFactor + $units) * $unitPrice, 2);

                ReturnLine::create([
                    'return_id' => $return->id,
                    'invoice_line_id' => $lineId,
                    'line_number' => $lineData['line_number'],
                    'product_id' => $lineData['product_id'],
                    'product_description' => $lineData['product_description'],
                    'quantity_box' => $boxes,
                    'quantity' => $units,
                    'line_total' => $lineTotal,
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

    /**
     * Actualiza una devolución existente (dentro de su ventana de edición).
     *
     * Regla de negocio: las devoluciones son absolutas. Una vez registradas,
     * solo pueden editarse el MISMO día calendario. Después de medianoche
     * Jaremar puede haber consumido la devolución vía API, por lo que
     * cualquier cambio posterior generaría inconsistencia.
     *
     * Flujo:
     *  1. Guard de manifiesto cerrado (misma defensa que createReturn)
     *  2. Guard de ventana de edición (isEditableToday)
     *  3. Validación server-side de cantidades contra OTRAS devoluciones
     *  4. Reemplazo total de las líneas (delete + recreate)
     *  5. Recálculo del total server-side
     *  6. Recálculo del manifest + invalidación de cache fuera de la TX
     *
     * Cambios permitidos:
     *  - return_reason_id
     *  - return_date
     *  - Cantidades por línea (modificar o agregar líneas olvidadas)
     *
     * NO se permite cambiar invoice_id ni warehouse_id: eso equivale a una
     * devolución distinta (borrar + crear nueva es el flujo correcto).
     *
     * @param  array  $data  Debe contener: return_reason_id, return_date, lines[]
     *
     * @throws ValidationException si el manifiesto está cerrado, la ventana
     *                             de edición expiró, o alguna línea excede
     *                             la cantidad disponible
     */
    public function updateReturn(InvoiceReturn $return, array $data): InvoiceReturn
    {
        // ── Guards previos a la transacción ──────────────────────────────
        // Se revisan fuera para fallar rápido sin abrir transacción inútil.
        if ($return->manifest->isClosed()) {
            throw ValidationException::withMessages([
                'id' => 'No se puede editar una devolución de un manifiesto cerrado.',
            ]);
        }

        if (! $return->isEditableToday()) {
            throw ValidationException::withMessages([
                'id' => 'La devolución solo puede editarse el día en que fue registrada.',
            ]);
        }

        DB::transaction(function () use ($return, $data) {
            // ── Race condition guard ─────────────────────────────────────
            // Mismo patrón que createReturn: lockForUpdate() sobre la factura
            // garantiza que las cantidades calculadas dentro de esta TX son
            // correctas aunque otro request concurrente toque la misma factura.
            $invoice = Invoice::with(['lines', 'manifest'])
                ->lockForUpdate()
                ->findOrFail($return->invoice_id);

            $linesData = $data['lines'] ?? [];

            // ── Validación server-side de cantidades ─────────────────────
            // Calculamos cantidades devueltas por OTRAS devoluciones
            // (excluye la actual para que el usuario pueda ver y modificar
            // sus propias cantidades sin que el máximo quede bloqueado en 0).
            $lineIds = $invoice->lines->pluck('id')->toArray();
            $returnedByLine = $this->getReturnedQuantitiesForLinesExcluding(
                $lineIds,
                $return->id
            );

            $linesByOriginalId = $invoice->lines->keyBy('id');
            $validationErrors = [];

            foreach ($linesData as $lineData) {
                $lineId = $lineData['invoice_line_id'] ?? null;
                $boxes = (float) ($lineData['quantity_box'] ?? 0);
                $units = (float) ($lineData['quantity'] ?? 0);

                if (! $lineId || ($boxes <= 0 && $units <= 0)) {
                    continue;
                }

                $originalLine = $linesByOriginalId[$lineId] ?? null;
                if (! $originalLine) {
                    continue;
                }

                $alreadyReturned = (float) ($returnedByLine[$lineId] ?? 0);
                $available = max(0, (float) $originalLine->quantity_fractions - $alreadyReturned);
                $convFactor = max(1, (float) ($originalLine->conversion_factor ?? 1));
                $requestedTotal = ($boxes * $convFactor) + $units;

                if ($requestedTotal > $available + 0.001) {
                    $validationErrors["lines.{$lineId}.quantity"] =
                        "Cantidad solicitada ({$requestedTotal} uds.) supera la disponible ({$available}) para {$originalLine->product_description}.";
                }
            }

            if (! empty($validationErrors)) {
                throw ValidationException::withMessages($validationErrors);
            }

            // ── Actualizar encabezado ────────────────────────────────────
            $return->update([
                'return_reason_id' => $data['return_reason_id'],
                'return_date' => $data['return_date'],
            ]);

            // ── Sincronizar líneas ───────────────────────────────────────
            // Borramos todas las líneas de esta devolución y recreamos con
            // los nuevos valores. line_total y el total de la devolución se
            // recalculan server-side para no depender del frontend.
            $return->lines()->delete();

            $newTotal = 0.0;

            foreach ($linesData as $lineData) {
                $lineId = $lineData['invoice_line_id'] ?? null;
                $boxes = (float) ($lineData['quantity_box'] ?? 0);
                $units = (float) ($lineData['quantity'] ?? 0);

                if ($boxes <= 0 && $units <= 0) {
                    continue;
                }

                $originalLine = $lineId ? ($linesByOriginalId[$lineId] ?? null) : null;
                $convFactor = max(1, (float) ($originalLine?->conversion_factor ?? 1));
                // Null → usar price; 0 → bonificación (gratis). Evitar ?: porque 0 es falsy.
                $unitPrice = $originalLine?->price_min_sale !== null
                    ? (float) $originalLine->price_min_sale
                    : (float) ($originalLine?->price ?? 0);
                $lineTotal = round(($boxes * $convFactor + $units) * $unitPrice, 2);

                ReturnLine::create([
                    'return_id' => $return->id,
                    'invoice_line_id' => $lineId,
                    'line_number' => $lineData['line_number'],
                    'product_id' => $lineData['product_id'],
                    'product_description' => $lineData['product_description'],
                    'quantity_box' => $boxes,
                    'quantity' => $units,
                    'line_total' => $lineTotal,
                ]);

                $newTotal += $lineTotal;
            }

            // Al editar, el type puede cambiar: recalculamos a partir del
            // nuevo total vs el pendiente (excluyendo esta devolución).
            // pendingExcludingThis = total_factura - devoluciones_otras
            $otherReturnsTotal = $invoice->returns()
                ->where('id', '!=', $return->id)
                ->whereIn('status', ['approved', 'pending'])
                ->sum('total');
            $pendingExcludingThis = max(0, (float) $invoice->total - (float) $otherReturnsTotal);
            $newType = (abs($newTotal - $pendingExcludingThis) < 0.01) ? 'total' : 'partial';

            $return->update([
                'total' => $newTotal,
                'type' => $newType,
            ]);

            $this->updateInvoiceStatus($invoice);
        });

        // ── Post-transacción ─────────────────────────────────────────────
        $this->recalculateManifestTotals($return->manifest_id);

        // Si la devolución ya estaba aprobada (regla absoluta: nacen aprobadas),
        // su processed_date ya indexa el cache. Invalidamos ese día para que
        // la API de Jaremar refleje los cambios.
        if ($return->processed_date) {
            $this->invalidateDevolucionesCache(
                $return->processed_date instanceof \DateTimeInterface
                    ? $return->processed_date->format('Y-m-d')
                    : (string) $return->processed_date
            );
        }

        return $return->fresh('lines');
    }

    public function approveReturn(InvoiceReturn $return, int $reviewedBy): void
    {
        // Proteger manifiestos cerrados. Un manifiesto puede cerrarse mientras
        // una devolución pendiente espera aprobación. Si se aprobara después
        // del cierre, los totales del manifiesto quedarían corruptos.
        if ($return->manifest->isClosed()) {
            throw new \RuntimeException(
                'No se puede aprobar una devolución de un manifiesto cerrado. '.
                'Reabre el manifiesto antes de aprobar.'
            );
        }

        $now = now();

        DB::transaction(function () use ($return, $reviewedBy, $now) {
            $return->update([
                'status' => 'approved',
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => $now,
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
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => now(),
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

        return max(0, (float) $invoice->total - (float) $totalReturned);
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
            $returned = (float) ($returnedByLine[$line->id] ?? 0);
            $available = (float) $line->quantity_fractions - $returned;
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
        // Nota sobre portabilidad: originalmente la expresión usaba
        // GREATEST(COALESCE(conversion_factor, 1), 1), que es específico de
        // PostgreSQL. SQLite (usado en tests) no reconoce GREATEST. La
        // traducción literal a CASE WHEN preserva la misma defensa contra
        // conversion_factor NULL o 0 y funciona en ambos motores.
        $result = ReturnLine::query()
            ->where('return_lines.invoice_line_id', $invoiceLineId)
            ->whereHas('return', fn ($q) => $q->whereIn('status', ['approved', 'pending']))
            ->join('invoice_lines', 'return_lines.invoice_line_id', '=', 'invoice_lines.id')
            ->selectRaw(
                'COALESCE(SUM('.
                    'return_lines.quantity_box * '.
                    '(CASE WHEN COALESCE(invoice_lines.conversion_factor, 1) < 1 '.
                    ' THEN 1 ELSE COALESCE(invoice_lines.conversion_factor, 1) END) '.
                    '+ return_lines.quantity'.
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
        // CASE WHEN reemplaza al GREATEST de Postgres por portabilidad a SQLite;
        // ver nota en getReturnedQuantity().
        return ReturnLine::query()
            ->whereIn('return_lines.invoice_line_id', $lineIds)
            ->whereHas('return', fn ($q) => $q->whereIn('status', ['approved', 'pending']))
            ->join('invoice_lines', 'return_lines.invoice_line_id', '=', 'invoice_lines.id')
            ->selectRaw(
                'return_lines.invoice_line_id, '.
                'COALESCE(SUM('.
                    'return_lines.quantity_box * '.
                    '(CASE WHEN COALESCE(invoice_lines.conversion_factor, 1) < 1 '.
                    ' THEN 1 ELSE COALESCE(invoice_lines.conversion_factor, 1) END) '.
                    '+ return_lines.quantity'.
                '), 0) AS total_returned'
            )
            ->groupBy('return_lines.invoice_line_id')
            ->pluck('total_returned', 'invoice_line_id')
            ->map(fn ($v) => (float) $v)
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
     * @param  int  $excludeReturnId  ID de la devolución a excluir
     * @return array<int, float>
     */
    public function getReturnedQuantitiesForLinesExcluding(array $lineIds, int $excludeReturnId): array
    {
        if (empty($lineIds)) {
            return [];
        }

        // CASE WHEN portable (ver getReturnedQuantity).
        return ReturnLine::query()
            ->whereIn('return_lines.invoice_line_id', $lineIds)
            ->where('return_lines.return_id', '!=', $excludeReturnId)
            ->whereHas('return', fn ($q) => $q->whereIn('status', ['approved', 'pending']))
            ->join('invoice_lines', 'return_lines.invoice_line_id', '=', 'invoice_lines.id')
            ->selectRaw(
                'return_lines.invoice_line_id, '.
                'COALESCE(SUM('.
                    'return_lines.quantity_box * '.
                    '(CASE WHEN COALESCE(invoice_lines.conversion_factor, 1) < 1 '.
                    ' THEN 1 ELSE COALESCE(invoice_lines.conversion_factor, 1) END) '.
                    '+ return_lines.quantity'.
                '), 0) AS total_returned'
            )
            ->groupBy('return_lines.invoice_line_id')
            ->pluck('total_returned', 'invoice_line_id')
            ->map(fn ($v) => (float) $v)
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

        // ── Recalcular total_returns (columna desnormalizada) ────────
        // Suma aprobadas + pendientes. Rechazadas = mercadería NO devuelta,
        // no deben reducir el saldo del cliente.
        $returnStats = $invoice->returns()
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status = 'approved' THEN total ELSE 0 END), 0) AS total_approved,
                COALESCE(SUM(CASE WHEN status = 'pending'  THEN total ELSE 0 END), 0) AS total_pending,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count
            ")
            ->first();

        $totalApproved = (float) ($returnStats->total_approved ?? 0);
        $totalReturns = $totalApproved + (float) ($returnStats->total_pending ?? 0);
        $hasPending = ((int) ($returnStats->pending_count ?? 0)) > 0;

        $hasAvailable = $this->hasAvailableLines($invoice);

        // Determinamos el nuevo status + total_returns en un solo update.
        $newStatus = match (true) {
            $totalApproved <= 0 && ! $hasPending => 'imported',
            ! $hasAvailable && ! $hasPending => 'returned',
            default => 'partial_return',
        };

        $invoice->update([
            'status' => $newStatus,
            'total_returns' => $totalReturns,
        ]);

        // ── Recalcular returned_quantity en invoice_lines ───────────
        // Una sola query UPDATE...FROM que recalcula TODAS las líneas
        // de esta factura de golpe. Fórmula en fracciones:
        // SUM(cajas × MAX(conversion_factor, 1) + unidades)
        // Solo devoluciones aprobadas + pendientes, no eliminadas.
        $this->recalculateLineReturnedQuantities($invoice->id);
    }

    /**
     * Recalcula `returned_quantity` para todas las líneas de una factura.
     *
     * Usa UPDATE...FROM con subquery agrupada: una sola query para
     * todas las líneas sin importar cuántas tenga la factura.
     *
     * Primero resetea a 0 todas las líneas de la factura (para cubrir
     * líneas que ya no tienen devoluciones), y luego aplica los valores
     * calculados desde return_lines.
     */
    protected function recalculateLineReturnedQuantities(int $invoiceId): void
    {
        // Paso 1: resetear a 0 todas las líneas de la factura.
        // Esto cubre líneas que antes tenían devoluciones pero ya no
        // (p.ej. se eliminó la última devolución que las tocaba).
        DB::table('invoice_lines')
            ->where('invoice_id', $invoiceId)
            ->where('returned_quantity', '>', 0)
            ->update(['returned_quantity' => 0]);

        // Paso 2: calcular y aplicar los valores reales.
        // CASE WHEN reemplaza GREATEST() por portabilidad (ver nota en
        // getReturnedQuantitiesForLines).
        DB::statement("
            UPDATE invoice_lines
            SET returned_quantity = sub.total_returned
            FROM (
                SELECT
                    rl.invoice_line_id,
                    COALESCE(SUM(
                        rl.quantity_box * (
                            CASE WHEN COALESCE(il.conversion_factor, 1) < 1
                                 THEN 1
                                 ELSE COALESCE(il.conversion_factor, 1)
                            END
                        ) + rl.quantity
                    ), 0) AS total_returned
                FROM return_lines rl
                JOIN invoice_lines il ON rl.invoice_line_id = il.id
                JOIN returns r ON rl.return_id = r.id
                WHERE r.deleted_at IS NULL
                  AND r.status IN ('approved', 'pending')
                  AND il.invoice_id = ?
                GROUP BY rl.invoice_line_id
            ) AS sub
            WHERE invoice_lines.id = sub.invoice_line_id
        ", [$invoiceId]);
    }

    // ─── Cancelar devolución ─────────────────────────────────

    /**
     * Cancela una devolución: cambia status a 'cancelled',
     * recalcula totales del manifiesto y refresca el status de la factura.
     *
     * A diferencia del soft-delete, el registro permanece visible
     * para trazabilidad y auditoría.
     */
    /**
     * Cancela una devolución y registra quién la canceló, cuándo y por qué.
     *
     * @param  string|null  $reason  Motivo de cancelación (obligatorio desde UI)
     */
    public function cancelReturn(InvoiceReturn $return, ?string $reason = null): void
    {
        if ($return->isCancelled()) {
            return;
        }

        $wasApproved = $return->isApproved();
        $processedDate = $return->processed_date?->toDateString();

        DB::transaction(function () use ($return, $reason) {
            $return->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => $reason,
            ]);
        });

        // Recalcular returned_quantity de las líneas de la factura
        $this->recalculateLineReturnedQuantities($return->invoice_id);

        // Recalcular totales del manifiesto
        Manifest::find($return->manifest_id)?->recalculateTotals();
        RecalculateManifestTotalsJob::dispatch($return->manifest_id);

        // Refrescar status de la factura (devuelta → parcial → importada)
        $invoice = $return->invoice()->first();
        if ($invoice) {
            $this->refreshInvoiceStatus($invoice);
        }

        // Invalidar cache de Jaremar si era aprobada
        if ($wasApproved && $processedDate) {
            $this->invalidateDevolucionesCache($processedDate);
        }
    }
}
