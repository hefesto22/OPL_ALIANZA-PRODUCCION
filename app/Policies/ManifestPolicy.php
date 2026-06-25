<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Manifest;
use App\Policies\Concerns\HandlesWarehouseScope;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ManifestPolicy
{
    use HandlesAuthorization;
    use HandlesWarehouseScope;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Manifest');
    }

    public function view(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('View:Manifest')
            && $this->userOwnsRecord($authUser, $manifest);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Manifest');
    }

    public function update(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Update:Manifest')
            && $this->userOwnsRecord($authUser, $manifest);
    }

    public function delete(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Delete:Manifest')
            && $this->userOwnsRecord($authUser, $manifest);
    }

    public function restore(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Restore:Manifest')
            && $this->userOwnsRecord($authUser, $manifest);
    }

    public function forceDelete(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('ForceDelete:Manifest')
            && $this->userOwnsRecord($authUser, $manifest);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Manifest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Manifest');
    }

    public function replicate(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Replicate:Manifest')
            && $this->userOwnsRecord($authUser, $manifest);
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Manifest');
    }

    /**
     * Cerrar un manifiesto (acción de negocio, permiso custom).
     *
     * Permiso: Close:Manifest (ver CustomPermissionSeeder). La condición
     * de estado (isReadyToClose) la valida la UI/Service — la Policy solo
     * autoriza al actor y lo restringe a SU bodega vía userOwnsRecord.
     */
    public function close(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Close:Manifest')
            && $this->userOwnsRecord($authUser, $manifest);
    }

    /**
     * Reabrir un manifiesto cerrado (acción sensible, permiso custom).
     *
     * Permiso: Reopen:Manifest. Por matriz solo lo tiene admin (+ super_admin
     * vía gate). userOwnsRecord deja pasar a usuarios globales.
     */
    public function reopen(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Reopen:Manifest')
            && $this->userOwnsRecord($authUser, $manifest);
    }
}
