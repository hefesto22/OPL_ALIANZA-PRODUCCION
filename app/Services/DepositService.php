<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\Manifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Servicio de depósitos. Cada operación corre dentro de una transacción
 * con bloqueo pesimista (lockForUpdate) sobre el manifiesto.
 *
 * Por qué el lock: los depósitos son operaciones financieras concurrentes.
 * Si dos usuarios registran depósitos del mismo manifiesto en paralelo, sin
 * lock ambos leen el mismo `total_to_deposit`, validan contra el mismo saldo
 * pendiente, y ambos commitean — quedando el manifiesto sobre-depositado y
 * la `difference` en negativo. El lock pesimista serializa esas operaciones
 * sobre la fila del manifiesto y elimina la carrera.
 *
 * Por qué el recálculo va DENTRO de la TX: el `recalculateTotals` toca
 * columnas financieras (total_deposited, difference, warehouse_totals). Si
 * la TX hace rollback, esas columnas deben volver al estado previo. Mantener
 * el recálculo dentro garantiza atomicidad ACID completa.
 *
 * Por qué las operaciones de archivo (deleteReceiptImage) usan DB::afterCommit:
 * el filesystem NO es transaccional. Si borráramos el archivo antes de la TX y
 * la TX hiciera rollback, quedaría una referencia rota (BD apunta a un archivo
 * que ya no existe). afterCommit ejecuta el borrado solo si la TX commiteó.
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
            // para garantizar que los saldos calculados a continuación reflejan
            // cualquier depósito recién commiteado por otra sesión.
            $manifestLocked = Manifest::query()
                ->whereKey($manifest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManifestOpen($manifestLocked);
            $this->assertAmountWithinPending($manifestLocked, (float) $data['amount']);

            $deposit = Deposit::create([
                ...$data,
                'manifest_id' => $manifestLocked->id,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // Recálculo dentro de la TX: si falla, rollback de TODO el depósito.
            $manifestLocked->recalculateTotals();

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
            // Lock sobre el manifiesto al que pertenece este depósito.
            $manifestLocked = Manifest::query()
                ->whereKey($deposit->manifest_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManifestOpen($manifestLocked);

            // Saldo pendiente excluyendo el depósito actual: permite editar el
            // mismo depósito sin que el saldo rechace el nuevo monto. Se calcula
            // con el manifiesto ya bloqueado para evitar TOCTOU.
            $depositFresh = $deposit->fresh();
            $pendingExcludingCurrent = max(
                0,
                (float) $manifestLocked->total_to_deposit
                    - $this->getTotalDeposited($manifestLocked)
                    + (float) $depositFresh->amount
            );

            $this->assertAmountWithinPending(
                $manifestLocked,
                (float) $data['amount'],
                $pendingExcludingCurrent
            );

            $deposit->update([
                ...$data,
                'updated_by' => $userId,
            ]);

            $manifestLocked->recalculateTotals();

            // El borrado físico del archivo viejo solo ocurre tras commit exitoso.
            // Si la TX hace rollback, el archivo queda intacto y la BD sigue
            // apuntando correctamente — sin referencias rotas.
            if ($shouldDeleteOld && $oldImage) {
                DB::afterCommit(function () use ($deposit, $oldImage) {
                    // Re-obtener el modelo del path actual por si cambió.
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
     * cancelled_at/cancelled_by/cancellation_reason; el manifest se
     * recalcula excluyendo este monto.
     *
     * Idempotente: cancelar un depósito ya cancelado es no-op (lockForUpdate
     * sobre el manifest sigue ocurriendo, pero no se duplican efectos).
     */
    public function cancelDeposit(Deposit $deposit, string $reason, int $userId): void
    {
        // Quick-return barato si ya está cancelado (sin abrir TX).
        if ($deposit->isCancelled()) {
            return;
        }

        // Capturar path del comprobante ANTES de la TX para borrarlo en
        // afterCommit. Una vez cancelado, la imagen ya no es operativa —
        // liberamos el espacio en disco. La metadata del depósito se
        // conserva (monto, razón, quién canceló) para auditoría.
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

            // Log explícito en canal finance — el trait LogsActivity del
            // modelo registra los cambios de columna automáticamente, pero
            // esta entrada documenta el evento de negocio con contexto rico
            // para responder "¿quién y por qué canceló este depósito?".
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
        // Si la TX hizo rollback, el archivo queda intacto y el campo
        // receipt_image en BD sigue apuntándolo correctamente.
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
     * El flujo normal de "anular" es cancelDeposit() — eso preserva el
     * registro. forceDelete se usa cuando se requiere eliminar por error
     * de captura (test data, prueba accidental, etc.).
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

            // Activity log ANTES del forceDelete: una vez borrado el modelo
            // no se puede performedOn() porque el id desaparece. El log
            // queda con causerId y propiedades del depósito al momento.
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

            // Borrar el archivo físico solo tras commit exitoso.
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
     * Diferencia pendiente de depositar.
     */
    public function getPendingAmount(Manifest $manifest): float
    {
        return max(0, (float) $manifest->total_to_deposit - $this->getTotalDeposited($manifest));
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
     * Verifica que el monto a depositar no exceda el saldo pendiente.
     *
     * Se acepta un margen de HNL 0.01 para cubrir diferencias de redondeo.
     * El parámetro $pendingOverride permite pasar un saldo pre-calculado
     * (útil en edición, donde el depósito actual debe excluirse del cálculo).
     */
    private function assertAmountWithinPending(
        Manifest $manifest,
        float $amount,
        ?float $pendingOverride = null,
    ): void {
        $pending = $pendingOverride ?? $this->getPendingAmount($manifest);

        if ($amount > $pending + 0.01) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'El monto (HNL %s) supera el saldo pendiente del manifiesto (HNL %s).',
                    number_format($amount, 2),
                    number_format($pending, 2)
                ),
            ]);
        }
    }
}
