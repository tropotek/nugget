<?php

namespace App\Ledger;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Reads holdings from the parcels, which the parcel matcher keeps in step
 * with the ledger.
 *
 * It does no authorising: the caller checks that the reader may see the user
 * or the asset it passes in.
 */
class Holdings
{
    /**
     * A holding for each of the user's assets, one with nothing held
     * included, keyed by asset id and in name order.
     *
     * It costs the same two queries however many assets there are: the
     * assets, then their open parcels.
     *
     * @return Collection<int, Holding>
     */
    public function forUser(User $user): Collection
    {
        return $user->assets()
            ->with(['parcels' => fn (HasMany $parcels) => $parcels->open()])
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->keyBy('id')
            ->map(fn (Asset $asset) => Holding::of($asset, $asset->parcels))
            ->toBase();
    }

    public function forAsset(Asset $asset): Holding
    {
        return Holding::of($asset, $asset->parcels()->open()->get());
    }
}
