<?php

declare(strict_types=1);

namespace App\Policies\Edt;

use App\Enums\Edt\EdtOrderStatus;
use App\Models\Edt\EdtOrder;
use App\Models\User;
use App\Policies\Concerns\HandlesWarehouseScope;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Preventas del EDT.
 *
 * - Vendedor (tiene ficha EdtSeller): toma preventas (create), corrige las
 *   suyas (update) y las anula (cancel) mientras la bodega no las toque.
 *   Solo ve las suyas.
 * - Bodega (encargado, admin): ve las de sus bodegas y las revisa (review:
 *   ajustar líneas, aprobar, rechazar) mientras estén ENVIADO o EN REVISIÓN.
 *   Los borradores de los vendedores no los ve nadie más.
 *
 * El estado lo vuelve a revisar EdtOrderService dentro del lock; aquí es
 * para mostrar u ocultar botones y cerrar el acceso por URL.
 */
class EdtOrderPolicy
{
    use HandlesAuthorization;
    use HandlesWarehouseScope;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EdtOrder');
    }

    public function view(AuthUser $authUser, EdtOrder $edtOrder): bool
    {
        return $authUser->can('View:EdtOrder')
            && $this->userOwnsRecord($authUser, $edtOrder)
            && $this->canSee($authUser, $edtOrder);
    }

    /**
     * Solo quien tiene ficha de vendedor activa toma preventas.
     */
    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EdtOrder')
            && $authUser instanceof User
            && (bool) $authUser->edtSeller?->is_active;
    }

    public function update(AuthUser $authUser, EdtOrder $edtOrder): bool
    {
        return $authUser->can('Update:EdtOrder')
            && $this->isOwnSeller($authUser, $edtOrder)
            && $edtOrder->status->isEditableBySeller();
    }

    public function cancel(AuthUser $authUser, EdtOrder $edtOrder): bool
    {
        return $authUser->can('Cancel:EdtOrder')
            && $this->isOwnSeller($authUser, $edtOrder)
            && $edtOrder->status->isEditableBySeller();
    }

    public function review(AuthUser $authUser, EdtOrder $edtOrder): bool
    {
        return $authUser->can('Review:EdtOrder')
            && $this->userOwnsRecord($authUser, $edtOrder)
            && ! $this->isOwnSeller($authUser, $edtOrder)
            && $edtOrder->status->isReviewable();
    }

    private function isOwnSeller(AuthUser $authUser, EdtOrder $edtOrder): bool
    {
        return $authUser instanceof User
            && $authUser->edtSeller !== null
            && (int) $authUser->edtSeller->id === (int) $edtOrder->seller_id;
    }

    /**
     * El vendedor solo ve las suyas; los demás no ven borradores.
     */
    private function canSee(AuthUser $authUser, EdtOrder $edtOrder): bool
    {
        if ($authUser instanceof User && $authUser->edtSeller !== null) {
            return $this->isOwnSeller($authUser, $edtOrder);
        }

        return $edtOrder->status !== EdtOrderStatus::Borrador;
    }
}
