<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\Manifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepositService
{
    /**
     * Crear un nuevo depósito y recalcular totales del manifiesto.
     */
    public function createDeposit(Manifest $manifest, array $data, int $userId): Deposit
    {
        $this->assertManifestOpen($manifest);
        $this->assertAmountWithinPending($manifest, (float) $data['amount']);

        // Si se subió imagen, registrar la fecha/hora de subida para el cleanup automático.
        if (! empty($data['receipt_image'])) {
            $data['receipt_image_uploaded_at'] = now();
        }

        $deposit = DB::transaction(function () use ($manifest, $data, $userId) {
            return Deposit::create([
                ...$data,
                'manifest_id' => $manifest->id,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        });

        // Para depósitos el recálculo es sincrónico: el usuario necesita ver
        // el saldo actualizado inmediatamente después de registrar el depósito.
        $manifest->recalculateTotals();

        return $deposit;
    }

    /**
     * Actualizar un depósito existente y recalcular totales.
     */
    public function updateDeposit(Deposit $deposit, array $data, int $userId): Deposit
    {
        $this->assertManifestOpen($deposit->manifest);

        // Al editar, el saldo pendiente se calcula excluyendo el depósito actual
        // para no rechazar un edit que no cambia el monto.
        $pendingExcludingCurrent = max(
            0,
            (float) $deposit->manifest->total_to_deposit
                - $this->getTotalDeposited($deposit->manifest)
                + (float) $deposit->amount  // devolver el monto actual al pool
        );

        $this->assertAmountWithinPending(
            $deposit->manifest,
            (float) $data['amount'],
            $pendingExcludingCurrent
        );

        $manifestId = $deposit->manifest_id;

        // Si se reemplaza la imagen, borrar la anterior del disco.
        $oldImage = $deposit->receipt_image;
        $newImage = $data['receipt_image'] ?? null;

        if ($oldImage && $newImage && $oldImage !== $newImage) {
            $deposit->deleteReceiptImage();
        }

        // Si se elimina la imagen (campo enviado como null/vacío), borrar del disco.
        if ($oldImage && array_key_exists('receipt_image', $data) && empty($newImage)) {
            $deposit->deleteReceiptImage();
        }

        // Actualizar timestamp si hay imagen nueva.
        if ($newImage && $newImage !== $oldImage) {
            $data['receipt_image_uploaded_at'] = now();
        }

        DB::transaction(function () use ($deposit, $data, $userId) {
            $deposit->update([
                ...$data,
                'updated_by' => $userId,
            ]);
        });

        $deposit->manifest->refresh();
        $deposit->manifest->recalculateTotals();

        return $deposit;
    }

    /**
     * Eliminar un depósito y recalcular totales.
     * También elimina el comprobante del disco si existe.
     */
    public function deleteDeposit(Deposit $deposit): void
    {
        $this->assertManifestOpen($deposit->manifest);

        $manifestId = $deposit->manifest_id;

        // Borrar imagen antes de eliminar el registro.
        $deposit->deleteReceiptImage();

        DB::transaction(function () use ($deposit) {
            $deposit->delete();
        });

        $manifest = Manifest::find($manifestId);
        $manifest?->recalculateTotals();
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
