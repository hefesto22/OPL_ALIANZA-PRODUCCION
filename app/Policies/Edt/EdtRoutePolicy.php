<?php

declare(strict_types=1);

namespace App\Policies\Edt;

use App\Models\Edt\EdtRoute;
use App\Policies\Concerns\HandlesWarehouseScope;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Rutas del EDT: permiso Shield + aislamiento por bodega.
 *
 * - assignSeller (permiso custom AssignSeller:EdtRoute): asignar, quitar o
 *   cancelar el vendedor de la ruta. Aparte de Update porque cambia qué
 *   clientes ve cada vendedor.
 * - delete: solo una ruta que nunca tuvo vendedor (la FK de las
 *   asignaciones es restrict). Con historial se desactiva.
 */
class EdtRoutePolicy
{
    use HandlesAuthorization;
    use HandlesWarehouseScope;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EdtRoute');
    }

    public function view(AuthUser $authUser, EdtRoute $edtRoute): bool
    {
        return $authUser->can('View:EdtRoute')
            && $this->userOwnsRecord($authUser, $edtRoute);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EdtRoute');
    }

    public function update(AuthUser $authUser, EdtRoute $edtRoute): bool
    {
        return $authUser->can('Update:EdtRoute')
            && $this->userOwnsRecord($authUser, $edtRoute);
    }

    public function delete(AuthUser $authUser, EdtRoute $edtRoute): bool
    {
        return $authUser->can('Delete:EdtRoute')
            && $this->userOwnsRecord($authUser, $edtRoute)
            && ! $edtRoute->assignments()->exists();
    }

    public function assignSeller(AuthUser $authUser, EdtRoute $edtRoute): bool
    {
        return $authUser->can('AssignSeller:EdtRoute')
            && $this->userOwnsRecord($authUser, $edtRoute);
    }
}
