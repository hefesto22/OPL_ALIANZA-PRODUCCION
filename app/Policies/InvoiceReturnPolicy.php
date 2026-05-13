<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InvoiceReturn;
use App\Policies\Concerns\HandlesWarehouseScope;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InvoiceReturnPolicy
{
    use HandlesAuthorization;
    use HandlesWarehouseScope;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InvoiceReturn');
    }

    public function view(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('View:InvoiceReturn')
            && $this->userOwnsRecord($authUser, $invoiceReturn);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InvoiceReturn');
    }

    public function update(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('Update:InvoiceReturn')
            && $this->userOwnsRecord($authUser, $invoiceReturn);
    }

    public function delete(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('Delete:InvoiceReturn')
            && $this->userOwnsRecord($authUser, $invoiceReturn);
    }

    public function restore(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('Restore:InvoiceReturn')
            && $this->userOwnsRecord($authUser, $invoiceReturn);
    }

    public function forceDelete(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('ForceDelete:InvoiceReturn')
            && $this->userOwnsRecord($authUser, $invoiceReturn);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:InvoiceReturn');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:InvoiceReturn');
    }

    public function replicate(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('Replicate:InvoiceReturn')
            && $this->userOwnsRecord($authUser, $invoiceReturn);
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InvoiceReturn');
    }
}
