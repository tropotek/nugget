<?php

namespace App\Ledger;

use App\Enums\PriceResolution;
use App\Models\Asset;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The value over time of one asset, or of a user's assets that count toward
 * the total: at each stored price, the units held then times the bid.
 *
 * The units are read from the ledger as it stood at each point, so a day
 * before a purchase is not valued with units bought later. An asset with no
 * price at a point keeps its last one, and is left out until it has one.
 *
 * Like Holdings, it does no authorising.
 */
class ValueSeries
{
    /**
     * A point for each date that has a day price, from one date to another,
     * both included. With no start it goes back to the first price. A moment
     * is read as its date in the owner's timezone.
     *
     * A day is valued with the units held at its end in the owner's timezone.
     *
     * @return Collection<int, ValuePoint>
     */
    public function daily(User|Asset $of, DateTimeInterface|string|null $from, DateTimeInterface|string $to): Collection
    {
        $timezone = $this->owner($of)->timezone;
        $from = $from === null ? null : Price::day($from, $timezone);
        $to = Price::day($to, $timezone);

        $endOfDay = fn (CarbonImmutable $date) => CarbonImmutable::parse($date->toDateString(), $timezone)->addDay()->utc();

        return $this->series(
            $of,
            PriceResolution::Day,
            $from,
            $to,
            $endOfDay($to),
            fn (Transaction $transaction, CarbonImmutable $date) => $transaction->occurred_at->lessThan($endOfDay($date)),
        );
    }

    /**
     * A point for each moment that has an intraday price, from one moment to
     * another, both included. Intraday prices are pruned, so this reaches
     * back two days at most.
     *
     * @return Collection<int, ValuePoint>
     */
    public function intraday(User|Asset $of, DateTimeInterface $from, DateTimeInterface $to): Collection
    {
        $to = CarbonImmutable::instance($to)->utc();

        return $this->series(
            $of,
            PriceResolution::Intraday,
            CarbonImmutable::instance($from)->utc(),
            $to,
            $to->addSecond(),
            fn (Transaction $transaction, CarbonImmutable $moment) => $transaction->occurred_at->lessThanOrEqualTo($moment),
        );
    }

    /**
     * Walks the prices and the ledger together, oldest first.
     *
     * @param  CarbonImmutable  $before  no transaction at or after this moment can count
     * @param  Closure(Transaction, CarbonImmutable): bool  $counts  whether a transaction has happened by a price's `at`
     * @return Collection<int, ValuePoint>
     */
    private function series(
        User|Asset $of,
        PriceResolution $resolution,
        ?CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $before,
        Closure $counts,
    ): Collection {
        $assetIds = $of instanceof Asset ? [$of->id] : $of->assets()->where('in_total', true)->pluck('id')->all();

        $prices = $this->prices($assetIds, $resolution, $from, $to);

        if ($prices->isEmpty()) {
            return collect();
        }

        $transactions = Transaction::query()
            ->whereIn('asset_id', $assetIds)
            ->where('occurred_at', '<', $before)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'asset_id', 'type', 'occurred_at', 'units'])
            ->all();

        $next = 0;
        $units = [];
        $bids = [];
        $points = [];

        foreach ($prices->groupBy(fn (Price $price) => $price->at->getTimestamp()) as $group) {
            $at = $group->first()->at->toImmutable();

            foreach ($group as $price) {
                $bids[$price->asset_id] = $price->bid;
            }

            // A price from before the start is only there to be carried forward.
            if ($from !== null && $at->lessThan($from)) {
                continue;
            }

            while (isset($transactions[$next]) && $counts($transactions[$next], $at)) {
                $transaction = $transactions[$next++];
                $units[$transaction->asset_id] = ($units[$transaction->asset_id] ?? Decimal::zero())->add($transaction->signedUnits());
            }

            $value = Decimal::zero();

            foreach ($bids as $assetId => $bid) {
                $value = $value->add(($units[$assetId] ?? Decimal::zero())->mul($bid));
            }

            $points[] = new ValuePoint($at, $value->round(Decimal::AUD_SCALE));
        }

        return collect($points);
    }

    /**
     * The prices in the range, and each asset's last one before it, oldest first.
     *
     * @param  list<int>  $assetIds
     * @return Collection<int, Price>
     */
    private function prices(array $assetIds, PriceResolution $resolution, ?CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $columns = ['id', 'asset_id', 'at', 'bid'];

        $inRange = Price::query()
            ->whereIn('asset_id', $assetIds)
            ->where('resolution', $resolution)
            ->where('at', '<=', $to)
            ->when($from, fn (Builder $query) => $query->where('at', '>=', $from))
            ->orderBy('at')
            ->orderBy('id')
            ->get($columns);

        if ($from === null) {
            return $inRange->toBase();
        }

        $carried = Price::query()
            ->whereIn('asset_id', $assetIds)
            ->where('resolution', $resolution)
            ->whereRaw(
                'prices.at = (select max(earlier.at) from prices as earlier where earlier.asset_id = prices.asset_id and earlier.resolution = ? and earlier.at < ?)',
                [$resolution->value, $from->toDateTimeString()],
            )
            ->orderBy('at')
            ->orderBy('id')
            ->get($columns);

        return $carried->toBase()->concat($inRange);
    }

    private function owner(User|Asset $of): User
    {
        return $of instanceof Asset ? $of->user : $of;
    }
}
