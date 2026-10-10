<?php

namespace App\Ledger\Matching;

use App\Ledger\HoldingPeriod;
use App\Models\Parcel;
use App\Models\Transaction;
use App\Support\Decimal;

/**
 * The parcel with the smallest taxable gain per unit first, which gives the
 * sale the smallest taxable gain it can have.
 *
 * A gain on a parcel held longer than 12 months counts for half, as the
 * discount will halve it. A loss counts in full, so the biggest loss goes
 * first of all. Parcels that come out equal go oldest first.
 */
class LowestGain implements MatchingStrategy
{
    /** Decimal places for a price or cost per unit, well past where two parcels could tie by rounding. */
    private const SCALE = 16;

    public function order(array $parcels, Transaction $sale): array
    {
        $price = $sale->total->div($sale->units, self::SCALE);

        $taxable = [];

        foreach ($parcels as $position => $parcel) {
            $taxable[$position] = $this->taxableGainPerUnit($parcel, $price, $sale);
        }

        // $parcels is oldest first, so the position settles a tie.
        $positions = array_keys($parcels);
        usort($positions, fn (int $a, int $b) => $taxable[$a]->compare($taxable[$b]) ?: $a <=> $b);

        return array_map(fn (int $position) => $parcels[$position], $positions);
    }

    private function taxableGainPerUnit(Parcel $parcel, Decimal $price, Transaction $sale): Decimal
    {
        $gain = $price->sub($parcel->cost_base->div($parcel->units, self::SCALE));

        $discounted = $gain->isPositive()
            && HoldingPeriod::isLongHeld($parcel->acquired_at, $sale->occurred_at, $sale->asset->user);

        return $discounted ? $gain->div(2, self::SCALE) : $gain;
    }
}
