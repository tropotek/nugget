<?php

namespace App\Ledger;

use App\Enums\ParcelMatching;
use App\Enums\TransactionType;
use App\Facades\Settings;
use App\Ledger\Matching\MatchingStrategy;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Parcel;
use App\Models\Transaction;
use App\Support\Decimal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out the parcels and disposals from the transactions.
 *
 * A rematch throws the parcels and disposals away and replays the ledger in
 * time order, so its result depends on the transactions alone:
 *
 * - A buy, a find, a reward and an adjustment up each open a parcel. Its cost
 *   base is the transaction's total, which already includes the fee.
 * - A sale uses up parcels and writes a disposal for each. Its proceeds are
 *   the transaction's total, which already has the fee off. Which parcels:
 *   the ones picked for it by hand, or else the order its matching method
 *   gives (oldest first, or lowest gain first).
 * - A transfer out and an adjustment down use up the oldest parcels with no
 *   disposal. What a transfer out removes waits to arrive somewhere.
 * - A transfer in takes what is waiting, oldest transfer first, and opens a
 *   parcel for each piece with the cost base and acquired date it left with.
 *   Any units beyond what is waiting open a parcel at the transfer's own
 *   date and total.
 *
 * A sale keeps the method it was first matched by in `matching`, so replaying
 * it later gives the same parcels whatever the setting has become.
 *
 * Transfers join a user's assets of the same symbol and unit (the same coin
 * on an exchange and in a wallet), so those are always rematched together.
 */
class ParcelMatcher
{
    /**
     * Rebuild the parcels and disposals of an asset, and of the same user's
     * other assets with its symbol and unit.
     *
     * A sale with no method recorded is matched by the given method, or by
     * the parcel matching setting when none is given, and remembers it.
     *
     * @throws InsufficientUnits when a transaction takes out more than is held
     * @throws InvalidParcelPick when a sale cannot have the parcels picked for it by hand
     */
    public function rematch(Asset $asset, ?ParcelMatching $method = null): void
    {
        DB::transaction(fn () => $this->replay($asset, $method));
    }

    /**
     * The parcels of a sale's asset that had units left at the moment before
     * the sale, oldest first: what the sale can be matched to. The stored
     * parcels cannot say, because they show what every later sale and
     * transfer has left.
     *
     * The ledger is replayed up to the sale and the replay undone, so the
     * parcels returned are not rows. Read them and never save them: their
     * ids mean nothing.
     *
     * @return Collection<int, Parcel>
     *
     * @throws InsufficientUnits|InvalidParcelPick when the ledger before the sale cannot be matched
     */
    public function openBefore(Transaction $sale): Collection
    {
        DB::beginTransaction();

        try {
            return collect($this->replay($sale->asset, null, $sale)[$sale->asset_id]);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Throw the pool's parcels and disposals away and replay its ledger in
     * time order: all of it, or the part before one transaction.
     *
     * @return array<int, list<Parcel>> the open parcels of each asset in the pool, oldest first
     */
    private function replay(Asset $asset, ?ParcelMatching $method = null, ?Transaction $before = null): array
    {
        $method ??= ParcelMatching::from(Settings::get('parcel_matching'));

        $assets = $this->pool($asset);
        $this->clear($asset);

        $open = $assets->map(fn () => [])->all();

        /** @var list<array{acquired_at: DateTimeInterface, units: Decimal, cost: Decimal, estimated: bool}> $inTransit */
        $inTransit = [];

        $transactions = Transaction::with('picks')->whereIn('asset_id', $assets->keys())->get();

        foreach ($this->inOrder($transactions) as $transaction) {
            if ($before && $transaction->is($before)) {
                break;
            }

            $transaction->setRelation('asset', $assets[$transaction->asset_id]);
            $parcels = &$open[$transaction->asset_id];

            match (true) {
                $transaction->type === TransactionType::TransferIn => $this->arrive($transaction, $parcels, $inTransit),
                $transaction->type === TransactionType::TransferOut => array_push($inTransit, ...$this->take($transaction, $parcels)),
                $transaction->type === TransactionType::Sell => $this->sell($transaction, $parcels, $method),
                $transaction->units->isNegative() => $this->take($transaction, $parcels),
                default => $this->open($parcels, $transaction, $transaction->occurred_at, $transaction->units, $transaction->total, $transaction->estimated),
            };

            unset($parcels);
        }

        return $open;
    }

    /**
     * Delete the parcels and disposals that a rematch of this asset would rebuild.
     */
    public function clear(Asset $asset): void
    {
        $assets = $this->pool($asset)->keys();

        Disposal::whereIn('parcel_id', Parcel::whereIn('asset_id', $assets)->select('id'))->delete();
        Parcel::whereIn('asset_id', $assets)->delete();
    }

    /**
     * The asset and the same user's other assets that transfers join to it.
     *
     * @return EloquentCollection<int, Asset> keyed by id
     */
    private function pool(Asset $asset): EloquentCollection
    {
        return Asset::with('user')
            ->where('user_id', $asset->user_id)
            ->where('symbol', $asset->symbol)
            ->where('unit', $asset->unit)
            ->get()
            ->keyBy('id');
    }

    /**
     * In time order. At the same moment, units come in before they go out,
     * and a transfer leaves before it arrives.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, Transaction>
     */
    private function inOrder(Collection $transactions): Collection
    {
        $rank = fn (Transaction $transaction) => match (true) {
            $transaction->type === TransactionType::TransferOut => 1,
            $transaction->type === TransactionType::TransferIn => 2,
            $transaction->type === TransactionType::Sell, $transaction->units->isNegative() => 3,
            default => 0,
        };

        return $transactions
            ->sortBy(fn (Transaction $transaction) => [$transaction->occurred_at->getTimestamp(), $rank($transaction), $transaction->id])
            ->values();
    }

    /**
     * @param  list<Parcel>  $parcels
     */
    private function open(array &$parcels, Transaction $transaction, DateTimeInterface $acquiredAt, Decimal $units, Decimal $costBase, bool $estimated): void
    {
        $parcel = $transaction->parcels()->create([
            'acquired_at' => $acquiredAt,
            'units' => $units,
            'units_remaining' => $units,
            'cost_base' => $costBase,
            'estimated' => $estimated,
        ]);

        // A transfer can bring in a parcel older than those already here.
        $parcels[] = $parcel;
        usort($parcels, fn (Parcel $a, Parcel $b) => [$a->acquired_at, $a->id] <=> [$b->acquired_at, $b->id]);
    }

    /**
     * Use up parcels for a transaction that takes units out: in the order the
     * strategy gives, or oldest first when there is none.
     *
     * @param  list<Parcel>  $parcels
     * @return list<array{parcel: Parcel, acquired_at: DateTimeInterface, units: Decimal, cost: Decimal, estimated: bool}>
     *
     * @throws InsufficientUnits
     */
    private function take(Transaction $transaction, array &$parcels, ?MatchingStrategy $strategy = null): array
    {
        $needed = $transaction->units->abs();
        $held = Decimal::sum(array_map(fn (Parcel $parcel) => $parcel->units_remaining, $parcels));

        if ($needed->greaterThan($held)) {
            throw InsufficientUnits::for($transaction, $held);
        }

        $pieces = [];

        foreach ($strategy?->order($parcels, $transaction) ?? $parcels as $parcel) {
            if (! $needed->isPositive()) {
                break;
            }

            $units = $needed->lessThan($parcel->units_remaining) ? $needed : $parcel->units_remaining;
            $pieces[] = $this->useUp($parcel, $units);
            $needed = $needed->sub($units);
        }

        $parcels = array_values(array_filter($parcels, fn (Parcel $parcel) => $parcel->isOpen()));

        return $pieces;
    }

    /**
     * Use up the parcels picked by hand for a sale. A pick names its parcel
     * by the transaction that acquired it and its acquired date.
     *
     * @param  list<Parcel>  $parcels
     * @return list<array{parcel: Parcel, acquired_at: DateTimeInterface, units: Decimal, cost: Decimal, estimated: bool}>
     *
     * @throws InvalidParcelPick
     */
    private function takePicked(Transaction $sale, array &$parcels): array
    {
        $picked = Decimal::sum($sale->picks->pluck('units'));

        if (! $picked->equals($sale->units)) {
            throw InvalidParcelPick::wrongTotal($sale, $picked);
        }

        $pieces = [];

        foreach ($sale->picks as $pick) {
            $needed = $pick->units;

            foreach ($parcels as $parcel) {
                $isThePick = $parcel->transaction_id === $pick->source_transaction_id
                    && $parcel->acquired_at->equalTo($pick->acquired_at);

                if (! $isThePick || ! $parcel->isOpen() || ! $needed->isPositive()) {
                    continue;
                }

                $units = $needed->lessThan($parcel->units_remaining) ? $needed : $parcel->units_remaining;
                $pieces[] = $this->useUp($parcel, $units);
                $needed = $needed->sub($units);
            }

            if ($needed->isPositive()) {
                throw InvalidParcelPick::unavailable($sale, $pick);
            }
        }

        $parcels = array_values(array_filter($parcels, fn (Parcel $parcel) => $parcel->isOpen()));

        return $pieces;
    }

    /**
     * Take units out of a parcel. The cost base of the piece is what the
     * parcel's remaining cost base falls by, so the pieces of a parcel
     * always add up to its cost exactly.
     *
     * @return array{parcel: Parcel, acquired_at: DateTimeInterface, units: Decimal, cost: Decimal, estimated: bool}
     */
    private function useUp(Parcel $parcel, Decimal $units): array
    {
        $costBefore = $parcel->remainingCostBase();

        $parcel->units_remaining = $parcel->units_remaining->sub($units);
        $parcel->save();

        return [
            'parcel' => $parcel,
            'acquired_at' => $parcel->acquired_at,
            'units' => $units,
            'cost' => $costBefore->sub($parcel->remainingCostBase()),
            'estimated' => $parcel->estimated,
        ];
    }

    /**
     * Each disposal's proceeds are its share of the sale by units. The shares
     * are cut from a running total, so they add up to the sale exactly.
     *
     * @param  list<Parcel>  $parcels
     */
    private function sell(Transaction $sale, array &$parcels, ParcelMatching $default): void
    {
        if ($sale->isHandMatched()) {
            $pieces = $this->takePicked($sale, $parcels);
        } else {
            if ($sale->matching === null) {
                $sale->forceFill(['matching' => $default])->save();
            }

            $pieces = $this->take($sale, $parcels, $sale->matching->strategy());
        }

        $unitsSoFar = Decimal::zero();
        $proceedsSoFar = Decimal::zero();

        foreach ($pieces as $piece) {
            $unitsSoFar = $unitsSoFar->add($piece['units']);
            $proceedsToHere = $sale->total->mul($unitsSoFar)->div($sale->units, Decimal::AUD_SCALE);
            $proceeds = $proceedsToHere->sub($proceedsSoFar);
            $proceedsSoFar = $proceedsToHere;

            $sale->disposals()->create([
                'parcel_id' => $piece['parcel']->id,
                'units' => $piece['units'],
                'cost_base' => $piece['cost'],
                'proceeds' => $proceeds,
                'gain' => $proceeds->sub($piece['cost']),
                'long_held' => HoldingPeriod::isLongHeld($piece['acquired_at'], $sale->occurred_at, $sale->asset->user),
            ]);
        }
    }

    /**
     * @param  list<Parcel>  $parcels
     * @param  list<array{acquired_at: DateTimeInterface, units: Decimal, cost: Decimal, estimated: bool}>  $inTransit
     */
    private function arrive(Transaction $transfer, array &$parcels, array &$inTransit): void
    {
        $needed = $transfer->units;

        while ($needed->isPositive() && $inTransit !== []) {
            $piece = $inTransit[0];

            if ($needed->lessThan($piece['units'])) {
                // Part of the piece arrives, with its share of the cost.
                $cost = $piece['cost']->mul($needed)->div($piece['units'], Decimal::AUD_SCALE);
                $this->open($parcels, $transfer, $piece['acquired_at'], $needed, $cost, $piece['estimated']);

                $inTransit[0]['units'] = $piece['units']->sub($needed);
                $inTransit[0]['cost'] = $piece['cost']->sub($cost);
                $needed = Decimal::zero();
            } else {
                $this->open($parcels, $transfer, $piece['acquired_at'], $piece['units'], $piece['cost'], $piece['estimated']);

                $needed = $needed->sub($piece['units']);
                array_shift($inTransit);
            }
        }

        if ($needed->isPositive()) {
            $this->open($parcels, $transfer, $transfer->occurred_at, $needed, $transfer->total, $transfer->estimated);
        }
    }
}
