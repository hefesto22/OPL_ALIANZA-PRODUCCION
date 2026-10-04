<?php

declare(strict_types=1);

namespace App\Policies\Edt;

use App\Models\Edt\EdtSupplier;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Proveedores del EDT: catálogo global de la empresa, no pertenece a una
 * bodega, así que no hay filtro por bodega — solo el permiso Shield.
 *
 * Solo las 5 acciones que usa el Resource (ver EdtPermissionSeeder). Sin
 * restore/forceDelete porque la tabla no tiene softDeletes.
 */
class EdtSupplierPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EdtSupplier');
    }

    public function view(AuthUser $authUser, EdtSupplier $edtSupplier): bool
    {
        return $authUser->can('View:EdtSupplier');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EdtSupplier');
    }

    public function update(AuthUser $authUser, EdtSupplier $edtSupplier): bool
    {
        return $authUser->can('Update:EdtSupplier');
    }

    public function delete(AuthUser $authUser, EdtSupplier $edtSupplier): bool
    {
        return $authUser->can('Delete:EdtSupplier');
    }
}
