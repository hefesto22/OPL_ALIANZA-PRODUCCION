<?php

declare(strict_types=1);

namespace App\Policies\Edt;

use App\Models\Edt\EdtClient;
use App\Policies\Concerns\HandlesWarehouseScope;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Clientes del EDT: cada cliente es de una bodega.
 *
 * Además del permiso Shield, un usuario de bodega solo puede ver, editar o
 * borrar clientes de SUS bodegas (HandlesWarehouseScope). Es la segunda
 * línea de defensa: el listado ya viene filtrado por WarehouseScope en
 * EdtClientResource::getEloquentQuery(), y esto cubre el acceso por URL o
 * por acción directa. Los usuarios globales (admin, super_admin) ven todos.
 *
 * Solo las 5 acciones que usa el Resource (ver EdtPermissionSeeder).
 */
class EdtClientPolicy
{
    use HandlesAuthorization;
    use HandlesWarehouseScope;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EdtClient');
    }

    public function view(AuthUser $authUser, EdtClient $edtClient): bool
    {
        return $authUser->can('View:EdtClient')
            && $this->userOwnsRecord($authUser, $edtClient);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EdtClient');
    }

    public function update(AuthUser $authUser, EdtClient $edtClient): bool
    {
        return $authUser->can('Update:EdtClient')
            && $this->userOwnsRecord($authUser, $edtClient);
    }

    /**
     * Hoy un cliente no tiene nada colgando y se puede borrar. Cuando
     * existan pedidos, aquí se agrega "y no tiene pedidos" (como
     * EdtSupplierPolicy con sus productos).
     */
    public function delete(AuthUser $authUser, EdtClient $edtClient): bool
    {
        return $authUser->can('Delete:EdtClient')
            && $this->userOwnsRecord($authUser, $edtClient);
    }
}
