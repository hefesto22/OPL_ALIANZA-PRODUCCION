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
     * Eliminar un depósito y recalcular totales.
     * También elimina el comprobante del disco si existe.
     */
    public function deleteDeposit(Deposit $deposit): void
    {
        // Capturamos el path antes de cualquier operación para usarlo en afterCommit.
        $receiptPath = $deposit->receipt_image;

        DB::transaction(function () use ($deposit, $receiptPath) {
            $manifestLocked = Manifest::query()
                ->whereKey($deposit->manifest_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertManifestOpen($manifestLocked);

            // Auditoría financiera DENTRO de la TX: si rollback, se descarta.
            // El trait LogsActivity del modelo Deposit genera un log automático
            // de "deleted" sobre default. Esta entrada extra en canal 'finance'
            // documenta el evento de negocio con contexto (monto, manifiesto)
            // para responder "¿quién y por qué borró este depósito?".
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
                ])
                ->log('Depósito eliminado');

            $deposit->delete();

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
     * Total depositado para un manifiesto.
     */
    public function getTotalDeposited(Manifest $manifest): float
    {
        return (float) $manifest->deposits()->sum('amount');
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
