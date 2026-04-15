<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InvoiceReturn;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InvoiceReturnPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:InvoiceReturn');
    }

    public function view(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('View:InvoiceReturn');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:InvoiceReturn');
    }

    public function update(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('Update:InvoiceReturn');
    }

    public function delete(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('Delete:InvoiceReturn');
    }

    public function restore(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('Restore:InvoiceReturn');
    }

    public function forceDelete(AuthUser $authUser, InvoiceReturn $invoiceReturn): bool
    {
        return $authUser->can('ForceDelete:InvoiceReturn');
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
        return $authUser->can('Replicate:InvoiceReturn');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:InvoiceReturn');
    }
}
