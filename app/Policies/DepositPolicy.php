<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Deposit;
use App\Policies\Concerns\HandlesWarehouseScope;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DepositPolicy
{
    use HandlesAuthorization;
    use HandlesWarehouseScope;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Deposit');
    }

    public function view(AuthUser $authUser, Deposit $deposit): bool
    {
        return $authUser->can('View:Deposit')
            && $this->userOwnsRecordViaRelation($authUser, $deposit, 'manifest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Deposit');
    }

    public function update(AuthUser $authUser, Deposit $deposit): bool
    {
        return $authUser->can('Update:Deposit')
            && $this->userOwnsRecordViaRelation($authUser, $deposit, 'manifest');
    }

    public function delete(AuthUser $authUser, Deposit $deposit): bool
    {
        return $authUser->can('Delete:Deposit')
            && $this->userOwnsRecordViaRelation($authUser, $deposit, 'manifest');
    }

    public function restore(AuthUser $authUser, Deposit $deposit): bool
    {
        return $authUser->can('Restore:Deposit')
            && $this->userOwnsRecordViaRelation($authUser, $deposit, 'manifest');
    }

    public function forceDelete(AuthUser $authUser, Deposit $deposit): bool
    {
        return $authUser->can('ForceDelete:Deposit')
            && $this->userOwnsRecordViaRelation($authUser, $deposit, 'manifest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Deposit');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Deposit');
    }

    public function replicate(AuthUser $authUser, Deposit $deposit): bool
    {
        return $authUser->can('Replicate:Deposit')
            && $this->userOwnsRecordViaRelation($authUser, $deposit, 'manifest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Deposit');
    }
}
