<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Aislamiento por bodega para Policies de modelos warehouse-scoped.
 *
 * Reglas de negocio:
 *   - Usuarios globales (warehouse_id = null): ven CUALQUIER registro. La
 *     decisión final queda en manos del permiso del rol.
 *   - Usuarios de bodega (warehouse_id = X): solo ven registros cuya bodega
 *     (directa o vía relación BelongsTo) coincide con la suya.
 *
 * Se usa como SEGUNDA línea de defensa después de WarehouseScope:
 *   - WarehouseScope filtra listados a nivel query (UI no muestra ajeno).
 *   - Este trait intercepta accesos por ID directo (URL, acciones, exports,
 *     bulk actions, restore, replicate). Sin este check, un operador de OAC
 *     con permiso View:Invoice puede pegar /admin/invoices/{id_de_OAS} en
 *     el browser y ver datos ajenos.
 *
 * Patrón de uso en una Policy (modelo con warehouse_id directo):
 *
 *   public function view(AuthUser $user, Invoice $invoice): bool
 *   {
 *       return $user->can('View:Invoice')
 *           && $this->userOwnsRecord($user, $invoice);
 *   }
 *
 * Patrón de uso en una Policy (modelo que filtra vía relación, como Deposit):
 *
 *   public function view(AuthUser $user, Deposit $deposit): bool
 *   {
 *       return $user->can('View:Deposit')
 *           && $this->userOwnsRecordViaRelation($user, $deposit, 'manifest');
 *   }
 */
trait HandlesWarehouseScope
{
    /**
     * El usuario tiene acceso al registro por su columna warehouse_id directa.
     *
     * Para modelos con columna `warehouse_id` propia (Invoice, Manifest,
     * InvoiceReturn).
     *
     * Edge cases:
     *   - Usuario global → siempre true (delega al permiso del rol).
     *   - Registro con warehouse_id NULL (ej. Invoice pending_warehouse sin
     *     asignar a bodega) → false para usuario de bodega. Solo el global
     *     puede manipular registros sin bodega asignada.
     *   - Cualquier otro caso → comparación estricta de IDs.
     */
    protected function userOwnsRecord(User $user, Model $record, string $column = 'warehouse_id'): bool
    {
        if ($user->isGlobalUser()) {
            return true;
        }

        $recordWarehouseId = $record->getAttribute($column);

        if ($recordWarehouseId === null) {
            return false;
        }

        return (int) $recordWarehouseId === (int) $user->warehouse_id;
    }

    /**
     * El usuario tiene acceso al registro vía una relación BelongsTo que
     * apunta a un modelo con warehouse_id propio.
     *
     * Para modelos sin warehouse_id directo (Deposit filtra via Manifest).
     *
     * Edge cases:
     *   - Usuario global → siempre true.
     *   - Relación soft-deleted o ausente ($related === null) → false para
     *     usuario de bodega. Sin contexto verificable no se puede afirmar
     *     que el registro le pertenece. El usuario global sigue pasando
     *     porque ya retornó arriba.
     *
     * Decisión consciente: NO usamos withTrashed() aquí para no contaminar
     * la relación normal del modelo en otros usos. Si el negocio luego
     * requiere que el operador de bodega pueda restaurar depósitos de un
     * manifiesto borrado, evaluamos cambiar a withTrashed con auditoría.
     */
    protected function userOwnsRecordViaRelation(
        User $user,
        Model $record,
        string $relation,
        string $column = 'warehouse_id'
    ): bool {
        if ($user->isGlobalUser()) {
            return true;
        }

        $related = $record->getRelationValue($relation);

        if ($related === null) {
            return false;
        }

        $relatedWarehouseId = $related->getAttribute($column);

        if ($relatedWarehouseId === null) {
            return false;
        }

        return (int) $relatedWarehouseId === (int) $user->warehouse_id;
    }
}
