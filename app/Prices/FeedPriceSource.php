<?php

namespace App\Prices;

use App\Models\Asset;
use App\Support\Decimal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The price of an asset from the Swissquote forex feed, by its feed symbol
 * (XAU/AUD). The feed quotes a metal by the troy ounce, so the price is
 * converted to the asset's unit when that is a weight.
 */
class FeedPriceSource implements PriceSource
{
    /** Grams in a troy ounce, the unit the feed quotes a metal in. */
    private const TROY_OUNCE = '31.1034768';

    /** Grams in each unit of weight an asset can be counted in. Any other unit is priced as the feed gives it. */
    private const GRAMS = [
        'oz' => self::TROY_OUNCE,
        'g' => '1',
        'kg' => '1000',
    ];

    public function quote(Asset $asset): ?Quote
    {
        try {
            $symbol = $asset->feed_symbol ?? throw new RuntimeException('it has no feed symbol');

            $quotes = Http::timeout(10)
                ->acceptJson()
                ->get(rtrim(config('nugget.prices.feed_url'), '/').'/'.$symbol)
                ->throw()
                ->json();

            // The first platform's first spread profile.
            $price = $quotes[0]['spreadProfilePrices'][0] ?? throw new RuntimeException('the feed has no quote for it');
            $quote = Quote::of($price['bid'] ?? null, $price['ask'] ?? null);

            return new Quote($this->perUnit($quote->bid, $asset), $this->perUnit($quote->ask, $asset));
        } catch (Throwable $e) {
            Log::warning("No price for asset {$asset->id} ({$asset->name}) from feed symbol {$asset->feed_symbol}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * A price for a troy ounce as a price for one of the asset's units.
     */
    private function perUnit(Decimal $perOunce, Asset $asset): Decimal
    {
        $grams = self::GRAMS[strtolower($asset->unit)] ?? self::TROY_OUNCE;

        return $perOunce->mul($grams)->div(self::TROY_OUNCE, Decimal::PRICE_SCALE);
    }
}
