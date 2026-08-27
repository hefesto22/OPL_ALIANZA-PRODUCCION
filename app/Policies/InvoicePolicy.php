<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Policies\Concerns\HandlesWarehouseScope;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InvoicePolicy
{
    use HandlesAuthorization;
    use HandlesWarehouseScope;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Invoice');
    }

    public function view(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('View:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Invoice');
    }

    public function update(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Update:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function delete(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Delete:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function restore(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Restore:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function forceDelete(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('ForceDelete:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Invoice');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Invoice');
    }

    public function replicate(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Replicate:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Invoice');
    }

    /**
     * Trasladar una factura de una bodega a otra
     * (App\Services\InvoiceWarehouseTransferService).
     *
     * Permiso personalizado — NO lo genera shield:generate. Vive aparte de
     * Update:Invoice a propósito: corregir datos de captura y mover plata
     * entre bodegas son dos poderes distintos. Un encargado que arregla el
     * nombre de un cliente no debería poder, de paso, sacarle una factura de
     * L 76,641 a la bodega vecina.
     *
     * Hoy lo ejerce ÚNICAMENTE el super_admin: la matriz de
     * RolePermissionSeeder no se lo asigna a ningún otro rol. Ojo — en este
     * proyecto `define_via_gate` está en false, así que el super_admin NO
     * tiene un Gate::before que le conceda todo: necesita el permiso asignado
     * en BD (lo hace `shield:super-admin`, o se marca desde Shield). Si algún
     * día hay que dárselo a alguien más, se marca ahí mismo, y el chequeo de
     * bodega de abajo sigue impidiendo que un usuario de bodega mueva
     * facturas que no son suyas.
     */
    public function transferWarehouse(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('TransferWarehouse:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }
}
