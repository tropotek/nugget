<?php

namespace App\Policies;

use App\Models\User;

/**
 * Only an admin manages users. Users are deactivated, never deleted.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function create(User $actor): bool
    {
        return $actor->isAdmin();
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Change the role or the active flag. An admin cannot change their own,
     * so there is always an active admin left.
     */
    public function changeAccess(User $actor, User $target): bool
    {
        return $actor->isAdmin() && ! $actor->is($target);
    }
}
