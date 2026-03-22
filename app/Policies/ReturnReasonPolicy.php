<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\ReturnReason;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReturnReasonPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ReturnReason');
    }

    public function view(AuthUser $authUser, ReturnReason $returnReason): bool
    {
        return $authUser->can('View:ReturnReason');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ReturnReason');
    }

    public function update(AuthUser $authUser, ReturnReason $returnReason): bool
    {
        return $authUser->can('Update:ReturnReason');
    }

    public function delete(AuthUser $authUser, ReturnReason $returnReason): bool
    {
        return $authUser->can('Delete:ReturnReason');
    }

    public function restore(AuthUser $authUser, ReturnReason $returnReason): bool
    {
        return $authUser->can('Restore:ReturnReason');
    }

    public function forceDelete(AuthUser $authUser, ReturnReason $returnReason): bool
    {
        return $authUser->can('ForceDelete:ReturnReason');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ReturnReason');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ReturnReason');
    }

    public function replicate(AuthUser $authUser, ReturnReason $returnReason): bool
    {
        return $authUser->can('Replicate:ReturnReason');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ReturnReason');
    }

}