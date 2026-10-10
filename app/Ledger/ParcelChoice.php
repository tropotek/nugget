<?php

namespace App\Ledger;

use App\Models\Disposal;
use App\Models\Parcel;
use App\Models\Transaction;
use App\Support\Decimal;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * A parcel a sale can be matched to by hand: what it had left at the moment
 * before the sale, and how much of it the sale uses now.
 *
 * Parcels with the same acquiring transaction and acquired date are one
 * choice, because that is how a pick names a parcel.
 */
final readonly class ParcelChoice
{
    /**
     * @param  string  $key  names the choice on the form
     * @param  Parcel  $parcel  one of the parcels, to hand to Ledger::pick(). It is not a row.
     * @param  Decimal  $units  the units left at the moment before the sale
     * @param  Decimal  $costBase  the cost base of those units
     * @param  Decimal  $picked  the units the sale uses now, however it was matched
     */
    public function __construct(
        public string $key,
        public Parcel $parcel,
        public DateTimeInterface $acquiredAt,
        public Decimal $units,
        public Decimal $costBase,
        public bool $estimated,
        public bool $longHeld,
        public Decimal $picked,
    ) {}

    /**
     * The choices a sale has, oldest first.
     *
     * @return Collection<string, self> keyed by `key`
     */
    public static function forSale(Transaction $sale, ParcelMatcher $matcher): Collection
    {
        $picked = $sale->disposals()->with('parcel')->get()
            ->groupBy(fn (Disposal $disposal) => self::keyOf($disposal->parcel))
            ->map(fn (Collection $disposals) => Decimal::sum($disposals->pluck('units')));

        return $matcher->openBefore($sale)
            ->groupBy(fn (Parcel $parcel) => self::keyOf($parcel))
            ->map(fn (Collection $parcels, string $key) => new self(
                key: $key,
                parcel: $parcels->first(),
                acquiredAt: $parcels->first()->acquired_at,
                units: Decimal::sum($parcels->map(fn (Parcel $parcel) => $parcel->units_remaining)),
                costBase: Decimal::sum($parcels->map(fn (Parcel $parcel) => $parcel->remainingCostBase())),
                estimated: $parcels->contains(fn (Parcel $parcel) => $parcel->estimated),
                longHeld: HoldingPeriod::isLongHeld($parcels->first()->acquired_at, $sale->occurred_at, $sale->asset->user),
                picked: $picked[$key] ?? Decimal::zero(),
            ));
    }

    /**
     * The gain from using this many of the units for the sale: their share
     * of the proceeds less their share of the cost base. The matcher's own
     * split can differ from it by a cent.
     */
    public function gainOn(Decimal $units, Transaction $sale): Decimal
    {
        $proceeds = $sale->total->mul($units)->div($sale->units, Decimal::AUD_SCALE);
        $cost = $this->costBase->mul($units)->div($this->units, Decimal::AUD_SCALE);

        return $proceeds->sub($cost);
    }

    /**
     * The gain on one unit, to compare the choices by.
     */
    public function gainPerUnit(Transaction $sale): Decimal
    {
        return $sale->total->div($sale->units, Decimal::PRICE_SCALE)
            ->sub($this->costBase->div($this->units, Decimal::PRICE_SCALE));
    }

    private static function keyOf(Parcel $parcel): string
    {
        return $parcel->transaction_id.'-'.$parcel->acquired_at->getTimestamp();
    }
}
