<?php

namespace App\Import\Passes;

use App\Enums\PriceResolution;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Models\Asset;
use App\Models\Price;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * tkbtc's ticks, a price for each market every few minutes, reduced to
 * Nugget's day prices: for each imported asset, one row a day in its owner's
 * timezone, holding the last tick of that day.
 *
 * A day that already has a price is left alone, so a second run adds
 * nothing and a price Nugget fetched itself is never replaced. The ticks of
 * a market nobody holds are not imported: there is no asset to price.
 *
 * The tick table is big (a million rows), so it is read a slice at a time,
 * each slice starting after the last tick of the one before.
 */
class TicksPass implements Pass
{
    /** How many ticks are read at once. */
    public const SLICE = 10000;

    /** How many day rows are written at once. */
    private const BATCH = 500;

    public function name(): string
    {
        return 'Ticks';
    }

    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('tick')->count();
    }

    /**
     * @return int how many day prices were added
     */
    public function run(ImportContext $context): int
    {
        $imported = 0;
        $assets = $this->assets($context);

        foreach ($assets as $row) {
            $asset = Asset::with('user')->find($context->newId(AssetsPass::TYPE, $row->asset_id));

            if ($asset) {
                $imported += $this->import($context, (int) $row->market_id, $asset);
            }
        }

        $unheld = $context->source->db()->table('tick')->distinct()->pluck('market_id')
            ->diff($assets->pluck('market_id'))->count();

        if ($unheld > 0) {
            $context->note("The ticks of {$unheld} ".($unheld === 1 ? 'market' : 'markets').' with no imported asset were not brought over.');
        }

        return $imported;
    }

    public function clear(ImportContext $context): void
    {
        // A day's price is the same whoever stored it, and goes with its asset when that is deleted.
    }

    private function import(ImportContext $context, int $marketId, Asset $asset): int
    {
        $timezone = $asset->user->timezone;
        $added = 0;
        $after = null;

        /** @var array{date: string, bid: string, ask: string, at: string}|null $day the day being read, and its last tick so far */
        $day = null;
        $rows = [];

        do {
            $ticks = $context->source->db()->table('tick')
                ->where('market_id', $marketId)
                ->when($after !== null, fn ($query) => $query->where('created', '>', $after))
                ->orderBy('created')
                ->limit(static::SLICE)
                ->get(['bid', 'ask', 'created']);

            foreach ($ticks as $tick) {
                $date = CarbonImmutable::parse($tick->created, 'UTC')->setTimezone($timezone)->toDateString();

                if ($day !== null && $day['date'] !== $date) {
                    $rows[] = $this->row($asset, $day);
                }

                $day = ['date' => $date, 'bid' => (string) $tick->bid, 'ask' => (string) $tick->ask, 'at' => (string) $tick->created];

                if (count($rows) >= self::BATCH) {
                    $added += $this->store($rows);
                    $rows = [];
                }
            }

            $after = $ticks->last()?->created;
        } while ($ticks->count() === static::SLICE);

        if ($day !== null) {
            $rows[] = $this->row($asset, $day);
        }

        return $added + $this->store($rows);
    }

    /**
     * A day row as the prices table holds it. Its `updated_at` is when the
     * tick was read, which is what a price shows as its "as at".
     *
     * @param  array{date: string, bid: string, ask: string, at: string}  $day
     * @return array<string, mixed>
     */
    private function row(Asset $asset, array $day): array
    {
        return [
            'asset_id' => $asset->id,
            'resolution' => PriceResolution::Day->value,
            'at' => Price::day($day['date'])->toDateTimeString(),
            'bid' => Decimal::of($day['bid'])->toFixed(Decimal::PRICE_SCALE),
            'ask' => Decimal::of($day['ask'])->toFixed(Decimal::PRICE_SCALE),
            'created_at' => $day['at'],
            'updated_at' => $day['at'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return int how many were new: a day that has a price already is passed over
     */
    private function store(array $rows): int
    {
        return $rows === [] ? 0 : DB::table('prices')->insertOrIgnore($rows);
    }

    /**
     * The tkbtc assets that were imported.
     *
     * @return Collection<int, object>
     */
    private function assets(ImportContext $context): Collection
    {
        return $context->source->db()->table('asset')->orderBy('asset_id')->get(['asset_id', 'market_id'])
            ->filter(fn (object $row) => $context->has(AssetsPass::TYPE, $row->asset_id))
            ->values();
    }
}
