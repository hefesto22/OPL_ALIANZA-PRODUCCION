<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Manifest;
use Illuminate\Auth\Access\HandlesAuthorization;

class ManifestPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Manifest');
    }

    public function view(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('View:Manifest');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Manifest');
    }

    public function update(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Update:Manifest');
    }

    public function delete(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Delete:Manifest');
    }

    public function restore(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Restore:Manifest');
    }

    public function forceDelete(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('ForceDelete:Manifest');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Manifest');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Manifest');
    }

    public function replicate(AuthUser $authUser, Manifest $manifest): bool
    {
        return $authUser->can('Replicate:Manifest');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Manifest');
    }

}