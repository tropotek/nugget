<?php

namespace App\Models\Contracts;

/**
 * A model that belongs to one user. App\Policies\OwnerPolicy covers every model that implements this.
 */
interface Owned
{
    /**
     * The id of the user this belongs to.
     */
    public function ownerId(): int;
}
