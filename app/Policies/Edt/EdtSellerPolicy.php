<?php

declare(strict_types=1);

namespace App\Policies\Edt;

use App\Models\Edt\EdtSeller;
use App\Policies\Concerns\HandlesWarehouseScope;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Vendedores del EDT: permiso Shield + aislamiento por bodega (el
 * encargado de OAC solo maneja vendedores de OAC).
 *
 * Sin delete: un vendedor con historial de rutas se desactiva, no se borra.
 */
class EdtSellerPolicy
{
    use HandlesAuthorization;
    use HandlesWarehouseScope;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EdtSeller');
    }

    public function view(AuthUser $authUser, EdtSeller $edtSeller): bool
    {
        return $authUser->can('View:EdtSeller')
            && $this->userOwnsRecord($authUser, $edtSeller);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EdtSeller');
    }

    public function update(AuthUser $authUser, EdtSeller $edtSeller): bool
    {
        return $authUser->can('Update:EdtSeller')
            && $this->userOwnsRecord($authUser, $edtSeller);
    }
}
