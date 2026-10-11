<?php

namespace App\Import\Passes;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Ledger\Holdings;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Transaction;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * tkbtc's balance log, turned into a ledger. tkbtc kept no purchases or
 * sales, only each change to an asset's balance, so every transaction made
 * here is a best guess, and a buy or a sale is flagged estimated:
 *
 * - a rise in an exchange asset's balance is a `buy` at the ask of the tick
 *   nearest in time, and a fall is a `sell` at its bid;
 * - a rise in a manual asset's balance (a metal, with no exchange) is a
 *   `found` at no cost, and is not flagged: found gold has none, and what
 *   was bought can have its cost put on by its owner;
 * - a fall in a manual asset's balance is a `sell` at the nearest bid.
 *
 * Each is keyed by its asset and the moment of the change, so a second run
 * adds none twice. An asset that already has transactions from its exchange
 * (the trade sync, or a backfill from the exchange's report) is left alone:
 * its real history has replaced these guesses. The parcels are matched once
 * an asset, at the end, and the units then held are checked against tkbtc's
 * own figure.
 */
class AssetLogPass implements Pass
{
    /** What an imported transaction's `external_id` starts with. */
    public const PREFIX = 'tkbtc-log-';

    public function __construct(
        private readonly ParcelMatcher $matcher,
        private readonly Holdings $holdings,
    ) {}

    public function name(): string
    {
        return 'Asset log';
    }

    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('asset_log')->count();
    }

    public function run(ImportContext $context): int
    {
        $imported = 0;
        $agree = 0;
        $assets = $this->assets($context);

        foreach ($assets as $row) {
            $asset = Asset::find($context->newId(AssetsPass::TYPE, $row->asset_id));

            if (! $asset) {
                continue;
            }

            // Once the exchange's own history is in, a guess beside it would count the same units twice.
            if ($asset->transactions()->where('source', TransactionSource::Exchange)->exists()) {
                $context->note("{$asset->name} has its real history from the exchange, so its balance log was not brought over.");
                $added = 0;
            } else {
                $added = $this->import($context, $row, $asset);
            }

            if ($added > 0) {
                $this->matcher->rematch($asset);
            }

            $imported += $added;
            $agree += $this->check($context, $row, $asset) ? 1 : 0;
        }

        $context->note("Units held agree with tkbtc for {$agree} of {$assets->count()} assets.");

        return $imported;
    }

    public function clear(ImportContext $context): void
    {
        $assets = Asset::whereKey($context->source->db()->table('asset')->pluck('asset_id')
            ->map(fn (mixed $id) => $context->newId(AssetsPass::TYPE, $id))->filter()->all())->get();

        foreach ($assets as $asset) {
            $imported = $asset->transactions()->where('source', TransactionSource::Import)->where('external_id', 'like', self::PREFIX.'%');

            if (! $imported->exists()) {
                continue;
            }

            // Parcels and disposals point at the transactions, so they go first and are rebuilt after.
            $this->matcher->clear($asset);
            $imported->delete();
            $this->matcher->rematch($asset);
        }
    }

    /**
     * @return int how many transactions were added to the asset
     */
    private function import(ImportContext $context, object $row, Asset $asset): int
    {
        $manual = $row->exchange_id === null;
        $have = $asset->transactions()->where('source', TransactionSource::Import)->pluck('external_id')->flip();
        $added = 0;

        $log = $context->source->db()->table('asset_log')->where('asset_id', $row->asset_id)->orderBy('created')->get(['units', 'created']);

        foreach ($log as $change) {
            $at = CarbonImmutable::parse($change->created, 'UTC');
            $key = self::PREFIX.$row->asset_id.'-'.$at->format('YmdHis');
            $units = Decimal::of((string) $change->units);

            if ($units->isZero() || $have->has($key)) {
                continue;
            }

            $type = match (true) {
                $units->isNegative() => TransactionType::Sell,
                $manual => TransactionType::Found,
                default => TransactionType::Buy,
            };

            $price = $type === TransactionType::Found ? Decimal::zero() : $this->priceAt($context, $row, $type, $at);

            if ($price === null) {
                $context->warn("{$asset->name}: the change of {$units} on {$at->toDateString()} has no tick to cost it with, so it was imported at \$0.");
                $price = Decimal::zero();
            }

            $units = $units->abs();

            $asset->transactions()->create([
                'type' => $type,
                'occurred_at' => $at,
                'units' => $units,
                'unit_price' => $price,
                'fee' => Decimal::zero(),
                'total' => Transaction::totalOf($type, $units, $price, Decimal::zero()),
                'source' => TransactionSource::Import,
                'external_id' => $key,
                // A buy or a sale is costed by a guess. What was found has no cost to guess at.
                'estimated' => $type !== TransactionType::Found,
                'notes' => $type === TransactionType::Found
                    ? 'Imported from tkbtc, which kept no cost for it, so it is held at no cost. Put a cost on this if it was bought.'
                    : 'Imported from tkbtc: a change in the balance, costed at the price nearest in time.',
            ]);

            $added++;
        }

        return $added;
    }

    /**
     * What a unit cost to buy, or sold for, at the tick nearest a moment:
     * the ask for a buy and the bid for a sale. Null when the market has no
     * ticks at all.
     */
    private function priceAt(ImportContext $context, object $row, TransactionType $type, CarbonImmutable $at): ?Decimal
    {
        $ticks = fn () => $context->source->db()->table('tick')->where('market_id', $row->market_id);
        $moment = $at->toDateTimeString();

        $before = $ticks()->where('created', '<=', $moment)->orderByDesc('created')->first();
        $after = $ticks()->where('created', '>', $moment)->orderBy('created')->first();

        $nearest = match (true) {
            $before === null => $after,
            $after === null => $before,
            default => $at->diffInSeconds(CarbonImmutable::parse($before->created, 'UTC'), true) <= $at->diffInSeconds(CarbonImmutable::parse($after->created, 'UTC'), true)
                ? $before
                : $after,
        };

        if ($nearest === null) {
            return null;
        }

        return Decimal::of((string) ($type === TransactionType::Sell ? $nearest->bid : $nearest->ask))->round(Decimal::PRICE_SCALE);
    }

    /**
     * Whether the units the ledger now holds are tkbtc's own figure.
     */
    private function check(ImportContext $context, object $row, Asset $asset): bool
    {
        $held = $this->holdings->forAsset($asset)->units;
        $theirs = Decimal::of((string) $row->units);

        if ($held->equals($theirs)) {
            return true;
        }

        $context->warn("{$asset->name}: the ledger holds {$held} {$asset->unit} and tkbtc has {$theirs}.");

        return false;
    }

    /**
     * The tkbtc assets that were imported, each with its market.
     *
     * @return Collection<int, object>
     */
    private function assets(ImportContext $context): Collection
    {
        return $context->source->db()->table('asset')
            ->join('market', 'market.market_id', '=', 'asset.market_id')
            ->orderBy('asset.asset_id')
            ->get(['asset.asset_id', 'asset.market_id', 'asset.units', 'market.exchange_id'])
            ->filter(fn (object $row) => $context->has(AssetsPass::TYPE, $row->asset_id))
            ->values();
    }
}
