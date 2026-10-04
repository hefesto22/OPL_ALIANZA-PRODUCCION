<?php

declare(strict_types=1);

namespace App\Policies\Edt;

use App\Models\Edt\EdtPriceTier;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Escalas mayoristas del EDT: configuración global. Cualquier cambio mueve
 * los precios de todo el catálogo (y queda en el historial de cada producto).
 */
class EdtPriceTierPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EdtPriceTier');
    }

    public function view(AuthUser $authUser, EdtPriceTier $edtPriceTier): bool
    {
        return $authUser->can('View:EdtPriceTier');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EdtPriceTier');
    }

    public function update(AuthUser $authUser, EdtPriceTier $edtPriceTier): bool
    {
        return $authUser->can('Update:EdtPriceTier');
    }

    public function delete(AuthUser $authUser, EdtPriceTier $edtPriceTier): bool
    {
        return $authUser->can('Delete:EdtPriceTier');
    }
}
