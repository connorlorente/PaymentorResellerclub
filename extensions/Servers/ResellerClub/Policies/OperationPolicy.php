<?php

namespace Paymenter\Extensions\Servers\ResellerClub\Policies;

use App\Models\User;
use App\Policies\BasePolicy;
use Paymenter\Extensions\Servers\ResellerClub\Models\Operation;

class OperationPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('admin.resellerclub.operations.view');
    }

    public function view(User $user, Operation $operation): bool
    {
        return $user->hasPermission('admin.resellerclub.operations.view');
    }

    /** Operations are written by the extension only. */
    public function create(User $user): bool
    {
        return false;
    }

    /** Retry is the only change an admin can make. */
    public function update(User $user, Operation $operation): bool
    {
        return $user->hasPermission('admin.resellerclub.operations.retry');
    }

    public function delete(User $user, Operation $operation): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
