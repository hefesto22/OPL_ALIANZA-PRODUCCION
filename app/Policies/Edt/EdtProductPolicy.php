<?php

declare(strict_types=1);

namespace App\Policies\Edt;

use App\Models\Edt\EdtProduct;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Productos del EDT: catálogo global (sin filtro por bodega).
 *
 * - Sin delete: un producto con historial de precios no se borra, se
 *   desactiva (FK restrict desde edt_product_price_history).
 * - changePrice: permiso aparte de update. Editar la descripción no es lo
 *   mismo que mover precios; "control de precios" está en el alcance del EDT.
 */
class EdtProductPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EdtProduct');
    }

    public function view(AuthUser $authUser, EdtProduct $edtProduct): bool
    {
        return $authUser->can('View:EdtProduct');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EdtProduct');
    }

    public function update(AuthUser $authUser, EdtProduct $edtProduct): bool
    {
        return $authUser->can('Update:EdtProduct');
    }

    public function changePrice(AuthUser $authUser, EdtProduct $edtProduct): bool
    {
        return $authUser->can('ChangePrice:EdtProduct');
    }
}
