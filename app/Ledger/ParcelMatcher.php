<?php

namespace App\Ledger;

use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Parcel;
use App\Models\Transaction;
use App\Support\Decimal;
use App\Support\LocalTime;
use DateTimeInterface;
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
 * - A sale uses up the oldest parcels first and writes a disposal for each.
 *   Its proceeds are the transaction's total, which already has the fee off.
 * - A transfer out and an adjustment down use up the oldest parcels with no
 *   disposal. What a transfer out removes waits to arrive somewhere.
 * - A transfer in takes what is waiting, oldest transfer first, and opens a
 *   parcel for each piece with the cost base and acquired date it left with.
 *   Any units beyond what is waiting open a parcel at the transfer's own
 *   date and total.
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
     * @throws InsufficientUnits when a transaction takes out more than is held. Nothing is changed.
     */
    public function rematch(Asset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $assets = Asset::with('user')
                ->where('user_id', $asset->user_id)
                ->where('symbol', $asset->symbol)
                ->where('unit', $asset->unit)
                ->get()
                ->keyBy('id');

            $transactions = Transaction::whereIn('asset_id', $assets->keys())->get();

            Disposal::whereIn('transaction_id', $transactions->modelKeys())->delete();
            Parcel::whereIn('asset_id', $assets->keys())->delete();

            /** @var array<int, list<Parcel>> $open the open parcels of each asset, oldest first */
            $open = $assets->map(fn () => [])->all();

            /** @var list<array{acquired_at: DateTimeInterface, units: Decimal, cost: Decimal, estimated: bool}> $inTransit */
            $inTransit = [];

            foreach ($this->inOrder($transactions) as $transaction) {
                $transaction->setRelation('asset', $assets[$transaction->asset_id]);
                $parcels = &$open[$transaction->asset_id];

                match (true) {
                    $transaction->type === TransactionType::TransferIn => $this->arrive($transaction, $parcels, $inTransit),
                    $transaction->type === TransactionType::TransferOut => array_push($inTransit, ...$this->take($transaction, $parcels)),
                    $transaction->type === TransactionType::Sell => $this->sell($transaction, $parcels),
                    $transaction->units->isNegative() => $this->take($transaction, $parcels),
                    default => $this->open($parcels, $transaction, $transaction->occurred_at, $transaction->units, $transaction->total, $transaction->estimated),
                };

                unset($parcels);
            }
        });
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
     * Use up the oldest parcels for a transaction that takes units out.
     *
     * The cost base of each piece is what the parcel's remaining cost base
     * falls by, so the pieces of a parcel always add up to its cost exactly.
     *
     * @param  list<Parcel>  $parcels
     * @return list<array{parcel: Parcel, acquired_at: DateTimeInterface, units: Decimal, cost: Decimal, estimated: bool}>
     *
     * @throws InsufficientUnits
     */
    private function take(Transaction $transaction, array &$parcels): array
    {
        $needed = $transaction->units->abs();
        $held = Decimal::sum(array_map(fn (Parcel $parcel) => $parcel->units_remaining, $parcels));

        if ($needed->greaterThan($held)) {
            throw InsufficientUnits::for($transaction, $held);
        }

        $pieces = [];

        while ($needed->isPositive()) {
            $parcel = $parcels[0];
            $units = $needed->lessThan($parcel->units_remaining) ? $needed : $parcel->units_remaining;
            $costBefore = $parcel->remainingCostBase();

            $parcel->units_remaining = $parcel->units_remaining->sub($units);
            $parcel->save();

            $pieces[] = [
                'parcel' => $parcel,
                'acquired_at' => $parcel->acquired_at,
                'units' => $units,
                'cost' => $costBefore->sub($parcel->remainingCostBase()),
                'estimated' => $parcel->estimated,
            ];

            $needed = $needed->sub($units);

            if (! $parcel->isOpen()) {
                array_shift($parcels);
            }
        }

        return $pieces;
    }

    /**
     * Each disposal's proceeds are its share of the sale by units. The shares
     * are cut from a running total, so they add up to the sale exactly.
     *
     * @param  list<Parcel>  $parcels
     */
    private function sell(Transaction $sale, array &$parcels): void
    {
        $unitsSoFar = Decimal::zero();
        $proceedsSoFar = Decimal::zero();

        foreach ($this->take($sale, $parcels) as $piece) {
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
                'long_held' => $this->isLongHeld($piece['acquired_at'], $sale),
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
            $piece = &$inTransit[0];

            if ($needed->lessThan($piece['units'])) {
                // Part of the piece arrives, with its share of the cost.
                $cost = $piece['cost']->mul($needed)->div($piece['units'], Decimal::AUD_SCALE);
                $this->open($parcels, $transfer, $piece['acquired_at'], $needed, $cost, $piece['estimated']);

                $piece['units'] = $piece['units']->sub($needed);
                $piece['cost'] = $piece['cost']->sub($cost);
                $needed = Decimal::zero();
            } else {
                $this->open($parcels, $transfer, $piece['acquired_at'], $piece['units'], $piece['cost'], $piece['estimated']);

                $needed = $needed->sub($piece['units']);
                unset($piece);
                array_shift($inTransit);
            }

            unset($piece);
        }

        if ($needed->isPositive()) {
            $this->open($parcels, $transfer, $transfer->occurred_at, $needed, $transfer->total, $transfer->estimated);
        }
    }

    /**
     * Held longer than 12 months, by the owner's calendar, not counting the
     * day of acquisition or the day of sale: a parcel acquired on 10 March is
     * long held when sold on or after 11 March the next year.
     */
    private function isLongHeld(DateTimeInterface $acquiredAt, Transaction $sale): bool
    {
        $owner = $sale->asset->user;

        $acquired = LocalTime::of($acquiredAt, $owner)->startOfDay();
        $sold = LocalTime::of($sale->occurred_at, $owner)->startOfDay();

        return $sold->greaterThan($acquired->addYearNoOverflow());
    }
}
