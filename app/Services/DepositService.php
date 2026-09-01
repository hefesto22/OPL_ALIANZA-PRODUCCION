<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\Manifest;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Servicio de depósitos. Cada operación corre dentro de una transacción
 * con bloqueo pesimista (lockForUpdate) sobre el manifiesto.
 *
 * Por qué el lock: los depósitos son operaciones financieras concurrentes.
 * Si dos usuarios registran depósitos del mismo manifiesto en paralelo, sin
 * lock ambos leen el mismo `total_to_deposit`, ambos calculan el mismo saldo
 * y ambos commitean — quedando los totales descuadrados. El lock pesimista
 * serializa esas operaciones sobre la fila del manifiesto.
 *
 * Por qué el recálculo va DENTRO de la TX: el `recalculateTotals` toca
 * columnas financieras (total_deposited, difference, warehouse_totals). Si
 * la TX hace rollback, esas columnas deben volver al estado previo.
 *
 * Por qué las operaciones de archivo (deleteReceiptImage) usan DB::afterCommit:
 * el filesystem NO es transaccional. Si borráramos el archivo antes de la TX y
 * la TX hiciera rollback, quedaría una referencia rota. afterCommit ejecuta el
 * borrado solo si la TX commiteó.
 *
 * ─────────────────────────────────────────────────────────────────────
 *  UN DEPÓSITO SE APLICA ÍNTEGRO A SU MANIFIESTO
 * ─────────────────────────────────────────────────────────────────────
 *  El monto puede superar el saldo pendiente: pasa cuando el encargado
 *  redondea la transferencia o deposita de más a propósito. En ese caso el
 *  manifiesto queda SOBREPAGADO y así se muestra — no se reparte nada a otros
 *  manifiestos ni se guarda un saldo a favor. Es una decisión de la operación:
 *  prefieren ver marcado exactamente dónde se depositó de más.
 *
 *  Lo único que se exige es una justificación escrita, porque es el caso que
 *  un auditor va a cuestionar meses después.
 */
class DepositService
{
    /**
     * Crear un nuevo depósito y recalcular totales del manifiesto.
     */
    public function createDeposit(Manifest $manifest, array $data, int $userId): Deposit
    {
        // Si se subió imagen, registrar la fecha/hora de subida para el cleanup automático.
        if (! empty($data['receipt_image'])) {
            $data['receipt_image_uploaded_at'] = now();
        }

        return DB::transaction(function () use ($manifest, $data, $userId) {
            // Lock pesimista sobre el manifiesto. Re-leemos la fila desde BD
            // para garantizar que el saldo calculado a continuación refleja
            // cualquier depósito recién commiteado por otra sesión.
            $manifestLocked = Manifest::query()
                ->whereKey($manifest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManifestOpen($manifestLocked);

            $amount = round((float) $data['amount'], 2);
            $pending = $this->getPendingAmount($manifestLocked);

            $this->assertJustifiedIfOverPending($data, $amount, $pending, $manifestLocked);

            $deposit = Deposit::create([
                ...$data,
                'manifest_id' => $manifestLocked->id,
                // La bodega puede venir del formulario (cuando hubo que
                // preguntarla) o resolverse sola. Ver resolveWarehouseId.
                'warehouse_id' => $data['warehouse_id']
                    ?? $this->resolveWarehouseId($manifestLocked, User::find($userId)),
                'amount' => $amount,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // Recálculo dentro de la TX: si falla, rollback de TODO el depósito.
            $manifestLocked->recalculateTotals();

            $this->logOverpaymentIfAny($deposit, $manifestLocked->refresh(), 'Depósito registrado por encima del total');

            return $deposit;
        });
    }

    /**
     * Actualizar un depósito existente y recalcular totales.
     */
    public function updateDeposit(Deposit $deposit, array $data, int $userId): Deposit
    {
        // Preparar metadatos de imagen fuera de la TX (solo cálculo, no IO).
        $oldImage = $deposit->receipt_image;
        $newImage = $data['receipt_image'] ?? null;
        $shouldDeleteOld = false;

        if ($oldImage && $newImage && $oldImage !== $newImage) {
            $shouldDeleteOld = true;
        }
        if ($oldImage && array_key_exists('receipt_image', $data) && empty($newImage)) {
            $shouldDeleteOld = true;
        }
        if ($newImage && $newImage !== $oldImage) {
            $data['receipt_image_uploaded_at'] = now();
        }

        return DB::transaction(function () use ($deposit, $data, $userId, $shouldDeleteOld, $oldImage) {
            $manifestLocked = Manifest::query()
                ->whereKey($deposit->manifest_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManifestOpen($manifestLocked);

            // Saldo pendiente excluyendo el depósito actual: permite editar el
            // mismo depósito sin que su propio monto cuente como ya cubierto.
            // Se calcula con el manifiesto bloqueado para evitar TOCTOU.
            $depositFresh = $deposit->fresh();
            $pendingExcludingCurrent = max(
                0,
                (float) $manifestLocked->total_to_deposit
                    - $this->getTotalDeposited($manifestLocked)
                    - (float) $manifestLocked->adjustment_amount
                    + (float) $depositFresh->amount
            );

            $amount = round((float) $data['amount'], 2);

            $this->assertJustifiedIfOverPending($data, $amount, $pendingExcludingCurrent, $manifestLocked);

            $deposit->update([
                ...$data,
                'amount' => $amount,
                'updated_by' => $userId,
            ]);

            $manifestLocked->recalculateTotals();

            $this->logOverpaymentIfAny($deposit, $manifestLocked->refresh(), 'Depósito modificado por encima del total');

            // El borrado físico del archivo viejo solo ocurre tras commit
            // exitoso. Si la TX hace rollback, el archivo queda intacto y la BD
            // sigue apuntándolo correctamente — sin referencias rotas.
            if ($shouldDeleteOld && $oldImage) {
                DB::afterCommit(function () use ($deposit, $oldImage) {
                    $deposit->receipt_image = $oldImage;
                    $deposit->deleteReceiptImage();
                });
            }

            return $deposit;
        });
    }

    /**
     * Cancelar un depósito con auditoría (soft-cancel con razón).
     *
     * El depósito permanece en BD para trazabilidad. Lo marcamos como
     * cancelled_at/cancelled_by/cancellation_reason; el manifiesto se
     * recalcula excluyendo este monto.
     *
     * Idempotente: cancelar un depósito ya cancelado es no-op.
     */
    public function cancelDeposit(Deposit $deposit, string $reason, int $userId): void
    {
        // Quick-return barato si ya está cancelado (sin abrir TX).
        if ($deposit->isCancelled()) {
            return;
        }

        // Capturar path del comprobante ANTES de la TX para borrarlo en
        // afterCommit. Una vez cancelado la imagen ya no es operativa —
        // liberamos el disco. La metadata del depósito se conserva.
        $receiptPath = $deposit->receipt_image;

        DB::transaction(function () use ($deposit, $reason, $userId) {
            $manifestLocked = Manifest::query()
                ->whereKey($deposit->manifest_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManifestOpen($manifestLocked);

            // Re-fetch dentro del lock por si otro proceso lo canceló mientras
            // tanto. Sin esto: race condition en cancel concurrente que duplica
            // el activity log.
            $deposit->refresh();
            if ($deposit->isCancelled()) {
                return;
            }

            $deposit->update([
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
                'updated_by' => $userId,
            ]);

            $manifestLocked->recalculateTotals();

            // Log explícito en canal finance — el trait LogsActivity del modelo
            // registra los cambios de columna automáticamente, pero esta entrada
            // documenta el evento de negocio con contexto rico para responder
            // "¿quién y por qué canceló este depósito?".
            activity('finance')
                ->performedOn($deposit)
                ->causedBy(auth()->user())
                ->withProperties([
                    'amount' => (float) $deposit->amount,
                    'deposit_date' => $deposit->deposit_date?->toDateString(),
                    'bank' => $deposit->bank,
                    'reference' => $deposit->reference,
                    'manifest_id' => $deposit->manifest_id,
                    'manifest_number' => $manifestLocked->number,
                    'reason' => $reason,
                ])
                ->log('Depósito cancelado');
        });

        // Borrar el archivo físico del comprobante solo tras commit exitoso.
        if ($receiptPath) {
            DB::afterCommit(function () use ($deposit, $receiptPath) {
                $deposit->receipt_image = $receiptPath;
                $deposit->deleteReceiptImage();
            });
        }
    }

    /**
     * Hard delete de un depósito — borrado permanente reservado para
     * super_admin (la Policy ForceDelete:Deposit lo restringe).
     *
     * El flujo normal de "anular" es cancelDeposit(), que preserva el
     * registro. forceDelete se usa para errores de captura (datos de prueba,
     * carga accidental).
     */
    public function forceDeleteDeposit(Deposit $deposit, int $userId): void
    {
        $receiptPath = $deposit->receipt_image;

        DB::transaction(function () use ($deposit, $receiptPath) {
            $manifestLocked = Manifest::query()
                ->whereKey($deposit->manifest_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManifestOpen($manifestLocked);

            // Activity log ANTES del forceDelete: una vez borrado el modelo no
            // se puede performedOn() porque el id desaparece.
            activity('finance')
                ->performedOn($deposit)
                ->causedBy(auth()->user())
                ->withProperties([
                    'amount' => (float) $deposit->amount,
                    'deposit_date' => $deposit->deposit_date?->toDateString(),
                    'bank' => $deposit->bank,
                    'reference' => $deposit->reference,
                    'manifest_id' => $deposit->manifest_id,
                    'manifest_number' => $manifestLocked->number,
                    'was_cancelled' => $deposit->isCancelled(),
                ])
                ->log('Depósito eliminado permanentemente');

            $deposit->forceDelete();

            $manifestLocked->recalculateTotals();

            if ($receiptPath) {
                DB::afterCommit(function () use ($deposit, $receiptPath) {
                    $deposit->receipt_image = $receiptPath;
                    $deposit->deleteReceiptImage();
                });
            }
        });
    }

    /**
     * Total depositado para un manifiesto — excluye cancelados.
     */
    public function getTotalDeposited(Manifest $manifest): float
    {
        return (float) $manifest->deposits()->active()->sum('amount');
    }

    /**
     * Saldo pendiente de depositar (nunca negativo).
     *
     * Descuenta también los ajustes de centavos: un manifiesto ajustado a cero
     * no tiene nada pendiente aunque sus depósitos no lleguen al total.
     */
    /**
     * Bodega a la que corresponde un depósito, cuando es deducible sin adivinar.
     *
     * Reglas, en orden:
     *   1. Si el manifiesto tiene UNA sola bodega, es esa. No importa quién
     *      deposite — en un manifiesto de una bodega no hay ambigüedad posible,
     *      y este es el 95% de los casos: nadie ve un campo nuevo.
     *   2. Si el usuario tiene exactamente UNA de las bodegas del manifiesto,
     *      es esa. El encargado de Santa Bárbara deposita lo de Santa Bárbara.
     *   3. Si no (usuario global, o de varias de las bodegas del manifiesto),
     *      devuelve NULL: hay que preguntárselo, y de eso se encarga la UI.
     *
     * Devolver NULL no bloquea el depósito. Se guarda sin bodega, sigue
     * contando en el total del manifiesto —que es lo que gobierna el
     * sobrepago y el cierre— y solo queda fuera del desglose por bodega.
     * Preferimos un depósito sin atribuir a uno atribuido a la bodega
     * equivocada: el primero se ve y se corrige, el segundo miente en
     * silencio dentro de un reporte financiero.
     */
    public function resolveWarehouseId(Manifest $manifest, ?User $user): ?int
    {
        $manifestWarehouseIds = $this->manifestWarehouseIds($manifest);

        if (count($manifestWarehouseIds) === 1) {
            return $manifestWarehouseIds[0];
        }

        if ($manifestWarehouseIds === [] || $user === null) {
            return null;
        }

        $userWarehouseIds = $user->warehouseIds();

        if ($userWarehouseIds === []) {
            return null;
        }

        $candidates = array_values(array_intersect($manifestWarehouseIds, $userWarehouseIds));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Opciones de bodega para el Select del formulario: las bodegas presentes
     * en ESE manifiesto, acotadas a las del usuario si es de bodega.
     *
     * No se ofrecen todas las bodegas del sistema a propósito — imputarle un
     * depósito a una bodega que no tiene facturas en el manifiesto genera una
     * fila fantasma en el desglose.
     *
     * @return array<int, string> [warehouse_id => código]
     */
    public function warehouseOptions(Manifest $manifest, ?User $user): array
    {
        $ids = $this->manifestWarehouseIds($manifest);

        if ($user !== null && $user->warehouseIds() !== []) {
            $ids = array_values(array_intersect($ids, $user->warehouseIds()));
        }

        return Warehouse::query()
            ->whereIn('id', $ids)
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }

    /**
     * Bodegas con facturas vivas en el manifiesto.
     *
     * @return array<int, int>
     */
    private function manifestWarehouseIds(Manifest $manifest): array
    {
        return Invoice::query()
            ->where('manifest_id', $manifest->id)
            ->whereNotNull('warehouse_id')
            ->distinct()
            ->pluck('warehouse_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function getPendingAmount(Manifest $manifest): float
    {
        return round(max(
            0,
            (float) $manifest->total_to_deposit
                - $this->getTotalDeposited($manifest)
                - (float) $manifest->adjustment_amount
        ), 2);
    }

    /**
     * Lanza excepción si el manifiesto está cerrado.
     * Última línea de defensa — protege la integridad aunque la UI falle.
     */
    private function assertManifestOpen(Manifest $manifest): void
    {
        if ($manifest->isClosed()) {
            throw ValidationException::withMessages([
                'manifest_id' => 'No se puede modificar un depósito de un manifiesto cerrado.',
            ]);
        }
    }

    /**
     * Exige justificación cuando el monto supera el saldo pendiente.
     *
     * Depositar de más está PERMITIDO — es lo que el cliente pidió: a veces
     * transfieren una cifra redondeada o de más a propósito. Lo que no se
     * permite es que quede sin explicación: ese manifiesto va a aparecer
     * sobrepagado y alguien, meses después, va a preguntar por qué.
     *
     * NOTA — Aquí vivía un margen de tolerancia de HNL 0.01
     * (`$amount > $pending + 0.01`) que dejaba pasar depósitos un centavo por
     * encima del pendiente sin pedir nada. Ese margen es el origen de los
     * manifiestos con difference = -0.01 que quedaron varados en producción,
     * imposibles de cerrar y sin rastro de quién los generó. Se eliminó: ahora
     * cualquier exceso, aunque sea de un centavo, queda justificado.
     */
    private function assertJustifiedIfOverPending(
        array $data,
        float $amount,
        float $pending,
        Manifest $manifest,
    ): void {
        if (round($amount, 2) <= round($pending, 2)) {
            return;
        }

        $justification = trim((string) ($data['justification'] ?? ''));

        if (mb_strlen($justification) < 15) {
            throw ValidationException::withMessages([
                'justification' => sprintf(
                    'El monto (HNL %s) supera el saldo pendiente del manifiesto #%s (HNL %s). '.
                    'Escribí una justificación de al menos 15 caracteres explicando por qué se deposita de más.',
                    number_format($amount, 2),
                    $manifest->number,
                    number_format($pending, 2)
                ),
            ]);
        }
    }

    /**
     * Deja constancia en el canal `finance` cuando el manifiesto queda
     * sobrepagado.
     *
     * Un depósito normal no genera ruido: el LogsActivity del modelo ya lo
     * cubre. Este registro existe para el caso excepcional, con el monto del
     * exceso y la justificación juntos, que es lo que hace falta para
     * responder la pregunta meses después.
     */
    private function logOverpaymentIfAny(Deposit $deposit, Manifest $manifest, string $event): void
    {
        if (! $manifest->isOverpaid()) {
            return;
        }

        activity('finance')
            ->performedOn($deposit)
            ->causedBy(auth()->user())
            ->withProperties([
                'amount' => (float) $deposit->amount,
                'bank' => $deposit->bank,
                'reference' => $deposit->reference,
                'manifest_number' => $manifest->number,
                'total_a_depositar' => (float) $manifest->total_to_deposit,
                'total_depositado' => (float) $manifest->total_deposited,
                'sobrepago' => $manifest->overpaidAmount(),
                'justificacion' => $deposit->justification,
            ])
            ->log($event);
    }
}
