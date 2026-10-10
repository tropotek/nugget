<?php

namespace App\Prices;

use App\Exchanges\ExchangeClient;
use App\Models\Asset;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The price of an asset from its market on its exchange, by ccxt's ticker.
 * The ticker is public, so the exchange's key is not needed and an inactive
 * exchange still gives a price.
 */
class ExchangePriceSource implements PriceSource
{
    public function __construct(private readonly ExchangeClient $client) {}

    public function quote(Asset $asset): ?Quote
    {
        try {
            $exchange = $asset->exchange ?? throw new RuntimeException('it has no exchange');

            if (! $this->client->supports($exchange, 'fetchTicker')) {
                throw new RuntimeException("{$exchange->driver} has no ticker");
            }

            $ticker = $this->client->for($exchange)->fetch_ticker($asset->market_id);

            // Some exchanges give only the last trade.
            return Quote::of($ticker['bid'] ?? $ticker['last'] ?? null, $ticker['ask'] ?? $ticker['last'] ?? null);
        } catch (Throwable $e) {
            Log::warning("No price for asset {$asset->id} ({$asset->name}) from market {$asset->market_id}: {$e->getMessage()}");

            return null;
        }
    }
}
