<?php

namespace App\Prices;

use App\Enums\ChartRange;
use App\Enums\PriceResolution;
use App\Models\Asset;
use App\Models\Price;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * An asset's stored prices as the points of a chart.
 */
class PriceChart
{
    /**
     * A [time, bid] pair for each price in the range, oldest first.
     *
     * The time is in milliseconds and is the owner's clock time read as UTC,
     * so a chart that draws its axis in UTC shows the owner's dates and
     * times. The bid is a decimal string.
     *
     * @return list<array{int, string}>
     */
    public function points(Asset $asset, ChartRange $range): array
    {
        $owner = $asset->user;
        $now = CarbonImmutable::now('UTC');
        $from = $range->start($now);

        if ($range->isIntraday()) {
            return $asset->prices()->intraday($from, $now)->get()
                ->map(fn (Price $price) => [LocalTime::asUtcMs($price->at, $owner), (string) $price->bid])
                ->all();
        }

        return $asset->prices()
            ->where('resolution', PriceResolution::Day)
            ->when($from, fn (Builder $query) => $query->where('at', '>=', Price::day($from, $owner->timezone)))
            ->orderBy('at')
            ->get()
            // A day row's time is already its date at 00:00:00.
            ->map(fn (Price $price) => [$price->at->getTimestampMs(), (string) $price->bid])
            ->all();
    }
}
