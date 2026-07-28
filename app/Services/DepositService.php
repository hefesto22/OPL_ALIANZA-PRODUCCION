<?php

namespace App\Services;

use App\Models\Deposit;
use App\Models\DepositAllocation;
use App\Models\Manifest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Servicio de depósitos. Cada operación corre dentro de una transacción
 * con bloqueo pesimista (lockForUpdate) sobre los manifiestos involucrados.
 *
 * Por qué el lock: los depósitos son operaciones financieras concurrentes.
 * Si dos usuarios registran depósitos del mismo manifiesto en paralelo, sin
 * lock ambos leen el mismo saldo pendiente, ambos validan contra él, y ambos
 * commitean — quedando el manifiesto sobre-depositado. El lock pesimista
 * serializa esas operaciones y elimina la carrera.
 *
 * Por qué el lock se toma SIEMPRE en orden ascendente de id: desde la
 * aplicación multi-manifiesto un depósito toca N manifiestos, no uno. Si dos
 * transacciones bloquearan el mismo par de manifiestos en orden distinto
 * (A→B y B→A), Postgres detectaría un deadlock y mataría una de las dos.
 * Ordenar por id da un orden total consistente y hace el deadlock imposible.
 * Esto NO es un detalle de estilo: es la única razón por la que dos bodegas
 * pueden depositar al mismo tiempo sin abortar transacciones.
 *
 * Por qué el recálculo va DENTRO de la TX: recalculateTotals toca columnas
 * financieras (total_deposited, difference, warehouse_totals). Si la TX hace
 * rollback esas columnas deben volver al estado previo. Mantenerlo dentro
 * garantiza atomicidad ACID completa.
 *
 * Por qué las operaciones de archivo (deleteReceiptImage) usan DB::afterCommit:
 * el filesystem NO es transaccional. Si borráramos el archivo antes de la TX y
 * la TX hiciera rollback, quedaría una referencia rota. afterCommit ejecuta el
 * borrado solo si la TX commiteó.
 */
class DepositService
{
    public function __construct(
        private readonly DepositAllocationService $allocations,
    ) {}

    /**
     * Previsualización del reparto para la UI, sin persistir nada.
     *
     * @return array<int, array{manifest_id: int, number: string, date: string|null, amount: float, is_origin: bool, is_overflow: bool}>
     */
    public function previewAllocationPlan(Manifest $manifest, float $amount, ?User $user = null): array
    {
        return $this->allocations->plan($manifest, $amount, $user);
    }

    /**
     * Crear un nuevo depósito, repartirlo entre manifiestos y recalcular
     * los totales de todos los afectados.
     *
     * `$data['allocations']` es opcional: si viene (array de
     * ['manifest_id' => int, 'amount' => float]) se respeta ese reparto
     * manual; si no, se calcula automáticamente por FIFO.
     */
    public function createDeposit(Manifest $manifest, array $data, int $userId): Deposit
    {
        // Si se subió imagen, registrar la fecha/hora para el cleanup automático.
        if (! empty($data['receipt_image'])) {
            $data['receipt_image_uploaded_at'] = now();
        }

        $manualPlan = $this->extractManualPlan($data);
        $amount = round((float) $data['amount'], 2);
        $user = $userId ? User::find($userId) : null;

        // Plan preliminar SIN lock: solo sirve para saber QUÉ manifiestos hay
        // que bloquear. El plan que se persiste se recalcula abajo, ya con los
        // manifiestos bloqueados y con saldos frescos.
        $preliminary = $manualPlan ?? $this->allocations->plan($manifest, $amount, $user);

        return DB::transaction(function () use ($manifest, $data, $userId, $user, $amount, $manualPlan, $preliminary) {
            $locked = $this->lockManifests(
                $this->manifestIdsOf($preliminary, $manifest->id)
            );

            $origin = $locked[$manifest->id];

            foreach ($locked as $target) {
                $this->assertManifestOpen($target);
            }

            $originPending = $this->allocations->pendingFor($origin);
            $this->assertJustifiedIfOverPending($data, $amount, $originPending, $origin);

            // Reparto definitivo, con los manifiestos ya bloqueados.
            $plan = $manualPlan
                ? $this->validateManualPlan($manualPlan, $amount, $locked)
                : $this->allocations->plan($origin, $amount, $user, $this->candidatesFrom($locked, $origin->id));

            $deposit = Deposit::create([
                ...$data,
                'manifest_id' => $origin->id,
                'amount' => $amount,
                'allocated_amount' => $amount,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->persistAllocations($deposit, $plan, $userId);
            $this->recalculateAll($locked, $plan);
            $this->logSplit($deposit, $plan, $origin, 'Depósito registrado');

            return $deposit;
        });
    }

    /**
     * Actualizar un depósito existente: se rehace el reparto completo.
     *
     * Cambiar el monto de una boleta que cubría tres manifiestos no tiene un
     * "ajuste parcial" sensato — se borra el reparto viejo y se recalcula de
     * cero. Ambos conjuntos de manifiestos (los que salen y los que entran)
     * quedan bloqueados y se recalculan dentro de la misma transacción.
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

        $manualPlan = $this->extractManualPlan($data);
        $amount = round((float) $data['amount'], 2);
        $user = $userId ? User::find($userId) : null;

        $previousIds = $deposit->allocations()->pluck('manifest_id')->all();
        $preliminary = $manualPlan ?? $this->allocations->plan($deposit->manifest, $amount, $user);

        return DB::transaction(function () use (
            $deposit, $data, $userId, $user, $amount, $manualPlan, $preliminary, $previousIds, $shouldDeleteOld, $oldImage
        ) {
            $locked = $this->lockManifests(array_merge(
                $previousIds,
                $this->manifestIdsOf($preliminary, $deposit->manifest_id)
            ));

            $origin = $locked[$deposit->manifest_id];

            foreach ($locked as $target) {
                $this->assertManifestOpen($target);
            }

            // Borrar el reparto viejo ANTES de calcular el nuevo: así
            // pendingFor() ya ve los saldos liberados y el mismo depósito no
            // compite consigo mismo por el pendiente que él mismo ocupaba.
            $deposit->allocations()->delete();

            $originPending = $this->allocations->pendingFor($origin);
            $this->assertJustifiedIfOverPending($data, $amount, $originPending, $origin);

            $plan = $manualPlan
                ? $this->validateManualPlan($manualPlan, $amount, $locked)
                : $this->allocations->plan($origin, $amount, $user, $this->candidatesFrom($locked, $origin->id));

            $deposit->update([
                ...$data,
                'amount' => $amount,
                'allocated_amount' => $amount,
                'updated_by' => $userId,
            ]);

            $this->persistAllocations($deposit, $plan, $userId);
            $this->recalculateAll($locked, $plan);
            $this->logSplit($deposit, $plan, $origin, 'Depósito modificado');

            // El borrado físico del archivo viejo solo ocurre tras commit
            // exitoso. Si la TX hace rollback, el archivo queda intacto y la
            // BD sigue apuntándolo correctamente — sin referencias rotas.
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
     * El depósito y su reparto permanecen en BD para trazabilidad; se marca
     * cancelled_at y TODOS los manifiestos que recibían dinero de esta boleta
     * se recalculan. Las allocations no se borran: el filtro por
     * deposits.cancelled_at las excluye al leer, así que restaurar el
     * depósito devuelve el reparto intacto sin backfill.
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
        $affectedIds = $deposit->allocations()->pluck('manifest_id')->all();

        DB::transaction(function () use ($deposit, $reason, $userId, $affectedIds) {
            $locked = $this->lockManifests(array_merge($affectedIds, [$deposit->manifest_id]));

            foreach ($locked as $target) {
                $this->assertManifestOpen($target);
            }

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

            foreach ($locked as $target) {
                $target->recalculateTotals();
            }

            // Log explícito en canal finance — el trait LogsActivity registra
            // los cambios de columna automáticamente, pero esta entrada
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
                    'manifest_number' => $locked[$deposit->manifest_id]->number ?? null,
                    'manifiestos_afectados' => collect($locked)->pluck('number')->values()->all(),
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
     * carga accidental). Las allocations se van por cascade de la FK.
     */
    public function forceDeleteDeposit(Deposit $deposit, int $userId): void
    {
        $receiptPath = $deposit->receipt_image;
        $affectedIds = $deposit->allocations()->pluck('manifest_id')->all();

        DB::transaction(function () use ($deposit, $receiptPath, $affectedIds) {
            $locked = $this->lockManifests(array_merge($affectedIds, [$deposit->manifest_id]));

            foreach ($locked as $target) {
                $this->assertManifestOpen($target);
            }

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
                    'manifest_number' => $locked[$deposit->manifest_id]->number ?? null,
                    'manifiestos_afectados' => collect($locked)->pluck('number')->values()->all(),
                    'was_cancelled' => $deposit->isCancelled(),
                ])
                ->log('Depósito eliminado permanentemente');

            $deposit->forceDelete();

            foreach ($locked as $target) {
                $target->recalculateTotals();
            }

            if ($receiptPath) {
                DB::afterCommit(function () use ($deposit, $receiptPath) {
                    $deposit->receipt_image = $receiptPath;
                    $deposit->deleteReceiptImage();
                });
            }
        });
    }

    /**
     * Total aplicado a un manifiesto — excluye depósitos cancelados.
     *
     * La fuente es deposit_allocations, no deposits: una boleta registrada
     * desde otro manifiesto puede tener dinero aplicado acá, y una registrada
     * acá puede tener parte aplicada en otro lado.
     */
    public function getTotalDeposited(Manifest $manifest): float
    {
        return DepositAllocation::totalForManifest($manifest->id);
    }

    /**
     * Diferencia pendiente de depositar (nunca negativa).
     */
    public function getPendingAmount(Manifest $manifest): float
    {
        return $this->allocations->pendingFor($manifest);
    }

    // ═══════════════════════════════════════════════════════════════
    //  Internos
    // ═══════════════════════════════════════════════════════════════

    /**
     * Bloquea los manifiestos indicados SIEMPRE en orden ascendente de id.
     * Ver la cabecera de la clase para por qué el orden es obligatorio.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, Manifest> indexado por id
     */
    private function lockManifests(array $ids): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        /** @var EloquentCollection<int, Manifest> $manifests */
        $manifests = Manifest::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($manifests->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'amount' => 'Uno de los manifiestos del reparto ya no existe. Recargá la pantalla e intentá de nuevo.',
            ]);
        }

        return $manifests->keyBy('id')->all();
    }

    /**
     * Ids de manifiesto presentes en un plan, más el de origen.
     *
     * @param  array<int, array{manifest_id: int, amount: float}>  $plan
     * @return array<int, int>
     */
    private function manifestIdsOf(array $plan, int $originId): array
    {
        return collect($plan)->pluck('manifest_id')->push($originId)->all();
    }

    /**
     * Candidatos ya bloqueados (todos menos el de origen), en orden FIFO.
     *
     * @param  array<int, Manifest>  $locked
     * @return \Illuminate\Support\Collection<int, Manifest>
     */
    private function candidatesFrom(array $locked, int $originId)
    {
        return collect($locked)
            ->reject(fn (Manifest $m) => (int) $m->id === $originId)
            ->sortBy([['date', 'asc'], ['id', 'asc']])
            ->values();
    }

    /**
     * Extrae y normaliza el reparto manual del payload, si viene.
     * Se saca de $data porque `allocations` no es una columna de deposits.
     *
     * @return array<int, array{manifest_id: int, amount: float}>|null
     */
    private function extractManualPlan(array &$data): ?array
    {
        if (! array_key_exists('allocations', $data)) {
            return null;
        }

        $raw = $data['allocations'];
        unset($data['allocations']);

        if (empty($raw)) {
            return null;
        }

        return collect($raw)
            ->map(fn ($line) => [
                'manifest_id' => (int) $line['manifest_id'],
                'amount' => round((float) $line['amount'], 2),
            ])
            ->all();
    }

    /**
     * Valida un reparto manual contra la invariante SUM(reparto) == monto y
     * contra el conjunto de manifiestos bloqueados.
     *
     * @param  array<int, array{manifest_id: int, amount: float}>  $plan
     * @param  array<int, Manifest>  $locked
     * @return array<int, array{manifest_id: int, number: string, date: string|null, amount: float, is_origin: bool, is_overflow: bool}>
     */
    private function validateManualPlan(array $plan, float $amount, array $locked): array
    {
        $sum = round(collect($plan)->sum('amount'), 2);

        if ($sum !== round($amount, 2)) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'El reparto suma HNL %s pero el depósito es de HNL %s. Todo el monto debe quedar aplicado.',
                    number_format($sum, 2),
                    number_format($amount, 2)
                ),
            ]);
        }

        return collect($plan)
            ->map(function (array $line) use ($locked) {
                if ($line['amount'] <= 0) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Cada línea del reparto debe tener un monto mayor a cero.',
                    ]);
                }

                $manifest = $locked[$line['manifest_id']] ?? null;

                if (! $manifest) {
                    throw ValidationException::withMessages([
                        'allocations' => 'El reparto incluye un manifiesto que ya no está disponible.',
                    ]);
                }

                return [
                    'manifest_id' => (int) $manifest->id,
                    'number' => (string) $manifest->number,
                    'date' => $manifest->date?->toDateString(),
                    'amount' => $line['amount'],
                    'is_origin' => false,
                    'is_overflow' => false,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{manifest_id: int, amount: float}>  $plan
     */
    private function persistAllocations(Deposit $deposit, array $plan, int $userId): void
    {
        foreach ($plan as $line) {
            DepositAllocation::create([
                'deposit_id' => $deposit->id,
                'manifest_id' => $line['manifest_id'],
                'amount' => $line['amount'],
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Recalcula los totales de todos los manifiestos tocados por la operación.
     *
     * @param  array<int, Manifest>  $locked
     * @param  array<int, array{manifest_id: int, amount: float}>  $plan
     *
     * @phpstan-ignore-next-line  $plan se conserva por simetría de la firma
     */
    private function recalculateAll(array $locked, array $plan): void
    {
        // Se recalculan TODOS los bloqueados, no solo los del plan: en un
        // update, los manifiestos que SALIERON del reparto también cambiaron
        // su saldo y quedarían con totales viejos si se omitieran.
        foreach ($locked as $manifest) {
            $manifest->recalculateTotals();
        }
    }

    /**
     * Deja constancia del reparto en el canal `finance` cuando la boleta
     * tocó más de un manifiesto o quedó sobre-depositada. Un depósito simple
     * (una boleta, un manifiesto, dentro del pendiente) no genera ruido:
     * el LogsActivity del modelo ya lo cubre.
     *
     * @param  array<int, array{manifest_id: int, number: string, amount: float, is_overflow?: bool}>  $plan
     */
    private function logSplit(Deposit $deposit, array $plan, Manifest $origin, string $event): void
    {
        $isOverflow = collect($plan)->contains(fn ($l) => ! empty($l['is_overflow']));

        if (count($plan) <= 1 && ! $isOverflow) {
            return;
        }

        activity('finance')
            ->performedOn($deposit)
            ->causedBy(auth()->user())
            ->withProperties([
                'amount' => (float) $deposit->amount,
                'bank' => $deposit->bank,
                'reference' => $deposit->reference,
                'manifiesto_origen' => $origin->number,
                'justificacion' => $deposit->justification,
                'sobredeposito' => $isOverflow,
                'reparto' => collect($plan)
                    ->map(fn ($l) => ['manifiesto' => $l['number'], 'monto' => $l['amount']])
                    ->values()
                    ->all(),
            ])
            ->log($event.' aplicado a '.count($plan).' manifiesto(s)');
    }

    /**
     * Lanza excepción si el manifiesto está cerrado.
     * Última línea de defensa — protege la integridad aunque la UI falle.
     */
    private function assertManifestOpen(Manifest $manifest): void
    {
        if ($manifest->isClosed()) {
            throw ValidationException::withMessages([
                'manifest_id' => "No se puede aplicar dinero al manifiesto #{$manifest->number}: está cerrado.",
            ]);
        }
    }

    /**
     * Exige justificación cuando el monto de la boleta supera el saldo
     * pendiente del manifiesto de origen.
     *
     * Ese es exactamente el caso que el cliente pidió habilitar (una sola
     * transferencia para varios manifiestos, o un depósito por encima del
     * total), y es el que un auditor va a cuestionar. Se valida acá y no solo
     * en el formulario: la UI se puede saltar, el Service no.
     *
     * NOTA — Aquí vivía un margen de tolerancia de HNL 0.01
     * (`$amount > $pending + 0.01`) que dejaba pasar depósitos un centavo por
     * encima del pendiente. Ese margen es el origen de los manifiestos con
     * difference = -0.01 que quedaron varados en producción (no se pueden
     * cerrar porque isReadyToClose exige cero exacto). Se eliminó a propósito:
     * ahora cualquier exceso, aunque sea de un centavo, se reparte o se
     * justifica explícitamente.
     */
    private function assertJustifiedIfOverPending(
        array $data,
        float $amount,
        float $pending,
        Manifest $origin,
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
                    $origin->number,
                    number_format($pending, 2)
                ),
            ]);
        }
    }
}
