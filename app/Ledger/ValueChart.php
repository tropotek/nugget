<?php

namespace App\Ledger;

use App\Enums\ChartRange;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;

/**
 * The value of a user's portfolio over time as the points of a chart.
 */
class ValueChart
{
    public function __construct(private readonly ValueSeries $series) {}

    /**
     * A [time, value] pair for each point of the user's value series in the
     * range, oldest first: intraday for the shortest range, daily otherwise.
     *
     * The time is in milliseconds and is the user's clock time read as UTC
     * (a day's is its date at 00:00). The value is a decimal string.
     *
     * @return list<array{int, string}>
     */
    public function points(User $user, ChartRange $range): array
    {
        $now = CarbonImmutable::now('UTC');
        $from = $range->start($now);

        if ($range->isIntraday()) {
            return $this->series->intraday($user, $from, $now)
                ->map(fn (ValuePoint $point) => [LocalTime::asUtcMs($point->at, $user), $point->value->toFixed(2)])
                ->all();
        }

        return $this->series->daily($user, $from, $now)
            ->map(fn (ValuePoint $point) => [$point->at->getTimestampMs(), $point->value->toFixed(2)])
            ->all();
    }
}
