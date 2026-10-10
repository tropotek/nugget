<?php

namespace App\Prices;

use App\Exchanges\Contracts\QuotesPrices;
use App\Exchanges\ExchangeDrivers;
use App\Models\Asset;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The price of an asset from its market on its exchange. Prices are public,
 * so the exchange's key is not needed and an inactive exchange still gives
 * one.
 */
class ExchangePriceSource implements PriceSource
{
    public function __construct(private readonly ExchangeDrivers $drivers) {}

    public function quote(Asset $asset): ?Quote
    {
        try {
            $exchange = $asset->exchange ?? throw new RuntimeException('it has no exchange');
            $driver = $this->drivers->for($exchange);

            if (! $driver instanceof QuotesPrices) {
                throw new RuntimeException("the {$exchange->driver} driver gives no prices");
            }

            return $driver->quote($asset->market_id);
        } catch (Throwable $e) {
            Log::warning("No price for asset {$asset->id} ({$asset->name}) from market {$asset->market_id}: {$e->getMessage()}");

            return null;
        }
    }
}
