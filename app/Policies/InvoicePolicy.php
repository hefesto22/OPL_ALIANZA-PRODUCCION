<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invoice;
use App\Policies\Concerns\HandlesWarehouseScope;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class InvoicePolicy
{
    use HandlesAuthorization;
    use HandlesWarehouseScope;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Invoice');
    }

    public function view(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('View:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Invoice');
    }

    public function update(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Update:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function delete(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Delete:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function restore(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Restore:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function forceDelete(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('ForceDelete:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Invoice');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Invoice');
    }

    public function replicate(AuthUser $authUser, Invoice $invoice): bool
    {
        return $authUser->can('Replicate:Invoice')
            && $this->userOwnsRecord($authUser, $invoice);
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Invoice');
    }
}
