<?php

namespace App\Ledger;

use App\Models\Asset;
use App\Models\Parcel;
use App\Support\Decimal;

/**
 * What a user holds of one asset now: the units left in its open parcels and
 * the AUD cost base of those units. It is worked out from the parcels each
 * time and never stored.
 */
final readonly class Holding
{
    /**
     * @param  int  $openParcels  the number of parcels with units left
     * @param  bool  $estimated  whether any of those parcels has an estimated cost
     */
    public function __construct(
        public Asset $asset,
        public Decimal $units,
        public Decimal $costBase,
        public int $openParcels,
        public bool $estimated,
    ) {}

    /**
     * @param  iterable<Parcel>  $parcels  the asset's parcels; any with no units left are passed over
     */
    public static function of(Asset $asset, iterable $parcels): self
    {
        $open = collect($parcels)->filter(fn (Parcel $parcel) => $parcel->isOpen());

        return new self(
            $asset,
            Decimal::sum($open->map(fn (Parcel $parcel) => $parcel->units_remaining)),
            Decimal::sum($open->map(fn (Parcel $parcel) => $parcel->remainingCostBase())),
            $open->count(),
            $open->contains(fn (Parcel $parcel) => $parcel->estimated),
        );
    }

    /**
     * The AUD cost of one unit, or null when nothing is held.
     */
    public function averageCost(): ?Decimal
    {
        return $this->isEmpty() ? null : $this->costBase->div($this->units, Decimal::PRICE_SCALE);
    }

    public function isEmpty(): bool
    {
        return $this->units->isZero();
    }
}
