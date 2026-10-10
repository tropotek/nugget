<?php

namespace App\Ledger;

use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Parcel;
use App\Models\ParcelPick;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * The way to change the ledger. Each change is saved and the parcels are
 * rematched in one database transaction, so a change the ledger cannot
 * support (a sale left short, a hand-picked parcel gone) is refused whole.
 */
class Ledger
{
    public function __construct(private readonly ParcelMatcher $matcher) {}

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InsufficientUnits|InvalidParcelPick
     */
    public function record(Asset $asset, array $attributes): Transaction
    {
        return DB::transaction(function () use ($asset, $attributes) {
            $transaction = $asset->transactions()->create($attributes);

            $this->matcher->rematch($asset);

            return $transaction;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws InsufficientUnits|InvalidParcelPick
     */
    public function amend(Transaction $transaction, array $attributes): Transaction
    {
        return DB::transaction(function () use ($transaction, $attributes) {
            $transaction->update($attributes);

            $this->matcher->rematch($transaction->asset);

            return $transaction;
        });
    }

    /**
     * @throws InsufficientUnits|InvalidParcelPick
     */
    public function remove(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $pick = ParcelPick::with('transaction.asset.user')
                ->where('source_transaction_id', $transaction->id)
                ->where('transaction_id', '!=', $transaction->id)
                ->first();

            if ($pick) {
                throw InvalidParcelPick::unavailable($pick->transaction, $pick);
            }

            // The transaction cannot be deleted while parcels or disposals point at it.
            $this->matcher->clear($transaction->asset);
            $transaction->delete();

            $this->matcher->rematch($transaction->asset);
        });
    }

    /**
     * Match a sale to parcels chosen by hand, in place of any chosen before.
     * The units picked must add up to the units sold.
     *
     * @param  list<array{parcel: Parcel, units: string}>  $picks
     *
     * @throws InvalidParcelPick
     */
    public function pick(Transaction $sale, array $picks): void
    {
        if ($sale->type !== TransactionType::Sell) {
            throw InvalidParcelPick::notASale($sale);
        }

        DB::transaction(function () use ($sale, $picks) {
            $sale->picks()->delete();

            foreach ($picks as ['parcel' => $parcel, 'units' => $units]) {
                if ($parcel->asset_id !== $sale->asset_id) {
                    throw InvalidParcelPick::otherAsset($sale);
                }

                $sale->picks()->create([
                    'source_transaction_id' => $parcel->transaction_id,
                    'acquired_at' => $parcel->acquired_at,
                    'units' => $units,
                ]);
            }

            $sale->forceFill(['matching' => null])->save();

            $this->matcher->rematch($sale->asset);
        });
    }

    /**
     * Drop a sale's hand-picked parcels and match it by the configured method.
     */
    public function unpick(Transaction $sale): void
    {
        DB::transaction(function () use ($sale) {
            $sale->picks()->delete();
            $sale->forceFill(['matching' => null])->save();

            $this->matcher->rematch($sale->asset);
        });
    }
}
