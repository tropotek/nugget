<?php

namespace App\Exchanges;

use App\Exchanges\Contracts\ListsMarkets;
use App\Models\Exchange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The markets of an exchange, for choosing the one that prices an asset.
 *
 * A list is the same for every account on an exchange, so it is kept by
 * driver, for a day. This never throws: an exchange that cannot be reached
 * is logged as a warning and has no markets for now, and nothing is kept, so
 * the next call asks again. A driver that cannot list its markets has none.
 */
class Markets
{
    /** How long a list is kept. */
    public const HOURS = 24;

    public function __construct(private readonly ExchangeDrivers $drivers) {}

    /**
     * Every market on the exchange, by market id, A to Z.
     *
     * @return list<Market>
     */
    public function for(Exchange $exchange): array
    {
        $key = self::cacheKey($exchange->driver);
        $kept = Cache::get($key);

        if (is_array($kept)) {
            return array_map(fn (array $market) => new Market(...$market), $kept);
        }

        try {
            if (! $this->drivers->has($exchange->driver)) {
                return [];
            }

            $driver = $this->drivers->for($exchange);

            if (! $driver instanceof ListsMarkets) {
                return [];
            }

            $markets = $driver->markets();
            usort($markets, fn (Market $a, Market $b) => $a->id <=> $b->id);
        } catch (Throwable $e) {
            Log::warning("The markets of exchange {$exchange->id} ({$exchange->label}) could not be read: {$e->getMessage()}");

            return [];
        }

        // Kept as plain arrays: a cache store does not hand an object back.
        if ($markets !== []) {
            Cache::put($key, array_map(fn (Market $market) => [$market->id, $market->base, $market->quote], $markets), now()->addHours(self::HOURS));
        }

        return $markets;
    }

    /**
     * The markets priced in AUD: the ones that can price an asset.
     *
     * @return list<Market>
     */
    public function inAud(Exchange $exchange): array
    {
        return array_values(array_filter($this->for($exchange), fn (Market $market) => $market->isInAud()));
    }

    /**
     * Whether the market can price an asset, or null when the exchange's
     * markets are not known and so nothing can be said.
     */
    public function prices(Exchange $exchange, string $marketId): ?bool
    {
        $markets = $this->inAud($exchange);

        return $markets === [] ? null : in_array($marketId, array_column($markets, 'id'), true);
    }

    /**
     * Drop the list kept for a driver, so the next call reads it again.
     */
    public function forget(string $driver): void
    {
        Cache::forget(self::cacheKey($driver));
    }

    private static function cacheKey(string $driver): string
    {
        return "exchange-markets:{$driver}";
    }
}
