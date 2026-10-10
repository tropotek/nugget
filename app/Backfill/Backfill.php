<?php

namespace App\Backfill;

use App\Enums\AssetClass;
use App\Enums\PriceResolution;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Ledger\Holdings;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Parcel;
use App\Models\Price;
use App\Models\Transaction;
use App\Support\Decimal;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Replaces what is guessed about an exchange's past with what its own
 * transaction report says happened.
 *
 * For the assets the report touches, the estimated transactions the tkbtc
 * import made are all removed, and so are trades the sync fetched up to the
 * moment of the report's last row, since the report has those too. The report's
 * entries are stored as the exchange's own (locked, keyed by the report's
 * transaction id, so a second run adds none twice), the parcels are matched
 * again, and the exchange's `trades_after` is set to the report's end so the
 * trade sync carries on from there.
 *
 * It is all one database transaction. A dry run does the same and rolls back.
 */
class Backfill
{
    /** What a backfilled transaction's `external_id` starts with. */
    public const PREFIX = 'csv-';

    /** How far the ledger and the report's final balance may differ: rounding to eight places. */
    private const TOLERANCE = '0.000001';

    /** @var array<string, Asset> by coin */
    private array $assets = [];

    /** @var array<string, list<array{int, Decimal}>> each coin's AUD trades in the report as [timestamp, unit price], oldest first */
    private array $reportPrices = [];

    public function __construct(
        private readonly ParcelMatcher $matcher,
        private readonly Holdings $holdings,
    ) {}

    public function run(Exchange $exchange, BtcMarketsCsv $report, bool $dryRun = false): BackfillResult
    {
        $result = new BackfillResult;
        $result->warnings = $report->warnings;

        if ($report->entries === [] || $report->endsAt === null) {
            return $result;
        }

        DB::beginTransaction();

        try {
            $this->apply($exchange, $report, $result);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $result;
    }

    private function apply(Exchange $exchange, BtcMarketsCsv $report, BackfillResult $result): void
    {
        $end = $report->endsAt;
        $this->assets = $this->assets($exchange, $report, $result);
        $this->reportPrices = $this->reportPrices($report);

        foreach ($this->assets as $asset) {
            $holding = $this->holdings->forAsset($asset);
            $result->summary[$asset->name]['before'] = [(string) $holding->units, $holding->costBase->toFixed(Decimal::AUD_SCALE)];
        }

        // Parcels and disposals point at the transactions, so they go first and are rebuilt at the end.
        foreach ($this->assets as $asset) {
            $this->matcher->clear($asset);
        }

        foreach ($this->assets as $asset) {
            // Every estimate, those after the report's end too: what happened since comes from the trade sync,
            // and a guess left beside it would count the same units twice.
            $result->estimatesRemoved += $asset->transactions()
                ->where('source', TransactionSource::Import)->where('estimated', true)->delete();
            $result->syncedRemoved += $asset->transactions()
                ->where('source', TransactionSource::Exchange)->where('occurred_at', '<=', $end)
                ->where(fn ($query) => $query->whereNull('external_id')->orWhere('external_id', 'not like', self::PREFIX.'%'))->delete();
        }

        $deposits = [];

        foreach ($report->entries as $entry) {
            $transaction = $this->store($entry, $result);

            if ($transaction && $entry->type === TransactionType::TransferIn) {
                $deposits[] = $transaction;
            }
        }

        $this->rematch();
        $this->flagDepositsWithoutCost($deposits, $result);

        foreach ($this->assets as $symbol => $asset) {
            $holding = $this->holdings->forAsset($asset);
            $result->summary[$asset->name]['after'] = [(string) $holding->units, $holding->costBase->toFixed(Decimal::AUD_SCALE)];

            $ledger = Decimal::sum($asset->transactions()->where('occurred_at', '<=', $end)->get()->map->signedUnits());
            $reported = $report->balances[$symbol] ?? Decimal::zero();

            if ($ledger->sub($reported)->abs()->greaterThan(self::TOLERANCE)) {
                $result->warnings[] = "{$asset->name}: at the end of the report the ledger holds {$ledger} {$asset->unit} and the report's balance is {$reported}.";
            }
        }

        if ($exchange->trades_after === null || $end->greaterThan($exchange->trades_after)) {
            $exchange->forceFill(['trades_after' => $end])->save();
        }
    }

    /**
     * The exchange's asset for each coin in the report, made where there is
     * none. A coin the account no longer holds is made archived.
     *
     * @return array<string, Asset>
     */
    private function assets(Exchange $exchange, BtcMarketsCsv $report, BackfillResult $result): array
    {
        $held = $exchange->tradedAssets();
        $assets = [];

        $symbols = array_unique(array_map(fn (Entry $entry) => $entry->symbol, $report->entries));
        sort($symbols);

        foreach ($symbols as $symbol) {
            $asset = $held->get("{$symbol}-AUD") ?? $held->first(fn (Asset $asset) => $asset->symbol === $symbol);

            if (! $asset) {
                $asset = new Asset([
                    'name' => $symbol,
                    'symbol' => $symbol,
                    'unit' => $symbol,
                    'class' => AssetClass::Crypto,
                    'exchange_id' => $exchange->id,
                    'market_id' => "{$symbol}-AUD",
                    'archived' => ($report->balances[$symbol] ?? Decimal::zero())->abs()->lessThan(self::TOLERANCE),
                    'notes' => 'Made by the backfill from the BTC Markets transaction report.',
                ]);
                $asset->forceFill(['user_id' => $exchange->user_id])->save();

                $result->assetsCreated[] = $symbol.($asset->archived ? ' (archived: none is held now)' : '');
            }

            $assets[$symbol] = $asset->setRelation('user', $exchange->user);
        }

        return $assets;
    }

    private function store(Entry $entry, BackfillResult $result): ?Transaction
    {
        $asset = $this->assets[$entry->symbol];
        $key = ['source' => TransactionSource::Exchange, 'external_id' => self::PREFIX.$entry->id];
        $units = $entry->units->round(Decimal::UNITS_SCALE);

        if ($units->isZero()) {
            return null;
        }

        if ($asset->transactions()->where($key)->exists()) {
            $result->alreadyThere++;

            return null;
        }

        [$total, $estimated, $notes] = $this->value($entry, $result);

        $transaction = $asset->transactions()->create([
            ...$key,
            'type' => $entry->type,
            'occurred_at' => $entry->at,
            'units' => $units,
            'unit_price' => $entry->unitPrice ?? ($entry->type->hasPrice() ? $total->div($units, Decimal::PRICE_SCALE) : Decimal::zero()),
            'fee' => $entry->fee ?? Decimal::zero(),
            'total' => $total,
            'estimated' => $estimated,
            'notes' => $notes,
        ]);

        $result->added[$entry->type->value] = ($result->added[$entry->type->value] ?? 0) + 1;

        return $transaction;
    }

    /**
     * An entry's AUD total, whether it is a best guess, and a note saying how it was reached.
     *
     * @return array{Decimal, bool, ?string}
     */
    private function value(Entry $entry, BackfillResult $result): array
    {
        if ($entry->total !== null) {
            return [$entry->total, false, null];
        }

        if ($entry->type === TransactionType::TransferIn || $entry->type === TransactionType::TransferOut) {
            return [Decimal::zero(), false, null];
        }

        if ($entry->airdrop) {
            return [Decimal::zero(), false, 'An airdrop: it came at no cost.'];
        }

        // A swap is valued by one of its two coins, the same one for both of its entries.
        $candidates = [[$entry->symbol, $entry->units]];

        if ($entry->isSwap()) {
            $candidates[] = [$entry->pairSymbol, $entry->pairUnits];
            usort($candidates, fn (array $a, array $b) => [$this->rank($a[0], $entry->at), $a[0] !== 'BTC', $a[0]] <=> [$this->rank($b[0], $entry->at), $b[0] !== 'BTC', $b[0]]);
        }

        [$symbol, $units] = $candidates[0];
        $what = $entry->isSwap()
            ? ($entry->type === TransactionType::Sell ? "Sold for {$entry->pairUnits} {$entry->pairSymbol}" : "Bought with {$entry->pairUnits} {$entry->pairSymbol}")
            : 'A reward';

        if ($price = $this->dayPrice($symbol, $entry->at)) {
            return [$units->mul($price)->round(Decimal::AUD_SCALE), false, "{$what}, valued in AUD at the {$symbol} price of the day."];
        }

        if ($price = $this->reportPrice($symbol, $entry->at)) {
            $result->valuedFromTheReport++;

            return [$units->mul($price)->round(Decimal::AUD_SCALE), true, "{$what}, valued in AUD at the nearest {$symbol} trade in the report: there is no price for the day."];
        }

        $result->notValued++;

        return [Decimal::zero(), true, "{$what}. No AUD price is known for it, so it is at \$0: put the real figure on this."];
    }

    /**
     * How well a coin can be valued at a moment: 0 with a price for the day, 1 from a trade in the report, 2 not at all.
     */
    private function rank(string $symbol, CarbonImmutable $at): int
    {
        return match (true) {
            $this->dayPrice($symbol, $at) !== null => 0,
            $this->reportPrice($symbol, $at) !== null => 1,
            default => 2,
        };
    }

    /**
     * The middle of the coin's bid and ask on the owner's day, when Nugget has a price for it.
     */
    private function dayPrice(string $symbol, CarbonImmutable $at): ?Decimal
    {
        $asset = $this->assets[$symbol] ?? null;

        if (! $asset) {
            return null;
        }

        $price = $asset->prices()
            ->where('resolution', PriceResolution::Day)
            ->where('at', Price::day($at, $asset->user->timezone))
            ->first();

        return $price?->mid();
    }

    /**
     * What the coin traded for in AUD at its trade in the report nearest in time.
     */
    private function reportPrice(string $symbol, CarbonImmutable $at): ?Decimal
    {
        $nearest = null;

        foreach ($this->reportPrices[$symbol] ?? [] as [$time, $price]) {
            $distance = abs($time - $at->getTimestamp());

            if ($nearest === null || $distance < $nearest[0]) {
                $nearest = [$distance, $price];
            }
        }

        return $nearest[1] ?? null;
    }

    /**
     * @return array<string, list<array{int, Decimal}>>
     */
    private function reportPrices(BtcMarketsCsv $report): array
    {
        $prices = [];

        foreach ($report->entries as $entry) {
            if ($entry->unitPrice !== null && $entry->unitPrice->isPositive()) {
                $prices[$entry->symbol][] = [$entry->at->getTimestamp(), $entry->unitPrice];
            }
        }

        return $prices;
    }

    private function rematch(): void
    {
        foreach ($this->assets as $asset) {
            $this->matcher->rematch($asset);
        }
    }

    /**
     * A deposit that brought back coins withdrawn earlier carries their cost
     * with it. One that did not has no cost anybody has told Nugget: its
     * parcel is at $0, and it is flagged estimated and listed.
     *
     * @param  list<Transaction>  $deposits
     */
    private function flagDepositsWithoutCost(array $deposits, BackfillResult $result): void
    {
        $flagged = false;

        foreach ($deposits as $deposit) {
            $costless = Parcel::where('transaction_id', $deposit->id)->where('acquired_at', $deposit->occurred_at)->get()
                ->filter(fn (Parcel $parcel) => $parcel->cost_base->isZero());

            if ($costless->isEmpty()) {
                continue;
            }

            $asset = $this->assets[$deposit->asset->symbol] ?? $deposit->asset;
            $units = Decimal::sum($costless->map->units);

            $deposit->forceFill([
                'estimated' => true,
                'notes' => 'A deposit of coins that were not bought on this exchange. Their cost is not known, so it is at $0: put the real cost on this.',
            ])->save();

            $result->depositsWithoutCost[] = LocalTime::of($deposit->occurred_at, $asset->user)->format('j M Y').": {$units} {$asset->unit} of {$asset->name}";
            $flagged = true;
        }

        if ($flagged) {
            $this->rematch();
        }
    }
}
