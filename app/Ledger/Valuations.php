<?php

namespace App\Ledger;

use App\Models\Asset;
use App\Models\Price;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Values holdings at each asset's latest price: its newest day row.
 *
 * Like Holdings, it does no authorising.
 */
class Valuations
{
    public function __construct(private readonly Holdings $holdings) {}

    /**
     * A valuation for each of the user's assets, keyed by asset id and in
     * name order. One more query than the holdings, however many assets
     * there are. Total them with PortfolioValue::of().
     *
     * @return Collection<int, Valuation>
     */
    public function forUser(User $user): Collection
    {
        $holdings = $this->holdings->forUser($user);
        $prices = Price::query()->latestPerAsset()->whereIn('asset_id', $holdings->keys())->get()->keyBy('asset_id');

        return $holdings->map(fn (Holding $holding, int $assetId) => new Valuation($holding, $prices->get($assetId)));
    }

    public function forAsset(Asset $asset): Valuation
    {
        return new Valuation($this->holdings->forAsset($asset), $asset->prices()->latestPerAsset()->first());
    }
}
