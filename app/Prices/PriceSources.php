<?php

namespace App\Prices;

use App\Enums\PricedBy;
use App\Models\Asset;

/**
 * The price of any asset, from whichever source it is priced by. An asset
 * with no price source has no quote.
 */
class PriceSources implements PriceSource
{
    public function __construct(
        private readonly ExchangePriceSource $exchange,
        private readonly FeedPriceSource $feed,
    ) {}

    public function quote(Asset $asset): ?Quote
    {
        return $this->for($asset)?->quote($asset);
    }

    public function for(Asset $asset): ?PriceSource
    {
        return match ($asset->pricedBy()) {
            PricedBy::Exchange => $this->exchange,
            PricedBy::Feed => $this->feed,
            PricedBy::Nothing => null,
        };
    }
}
