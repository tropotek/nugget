<?php

namespace App\Prices;

use App\Enums\PriceResolution;
use App\Models\Asset;
use App\Models\Price;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Stores a quote as the asset's price: an intraday row at the moment given,
 * and the day row of that moment's date in the owner's timezone. Each
 * replaces a row already there, so the day row holds the day's latest price.
 */
class PriceRecorder
{
    /**
     * @return Price the intraday row
     */
    public function record(Asset $asset, Quote $quote, DateTimeInterface $at): Price
    {
        $at = CarbonImmutable::instance($at)->utc();
        $price = ['bid' => $quote->bid, 'ask' => $quote->ask];

        return DB::transaction(function () use ($asset, $at, $price) {
            $asset->prices()->updateOrCreate(
                ['resolution' => PriceResolution::Day, 'at' => Price::day($at, $asset->user->timezone)],
                $price,
            );

            return $asset->prices()->updateOrCreate(['resolution' => PriceResolution::Intraday, 'at' => $at], $price);
        });
    }
}
