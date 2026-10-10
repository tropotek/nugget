<?php

namespace App\Policies;

use App\Models\Contracts\Owned;
use App\Models\User;

/**
 * The policy of every Owned model: a user works with their own and nobody
 * else's. There is no admin bypass, because the data is one person's finances.
 */
class OwnerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Owned $model): bool
    {
        return $model->ownerId() === $user->id;
    }

    public function update(User $user, Owned $model): bool
    {
        return $model->ownerId() === $user->id;
    }

    public function delete(User $user, Owned $model): bool
    {
        return $model->ownerId() === $user->id;
    }
}
