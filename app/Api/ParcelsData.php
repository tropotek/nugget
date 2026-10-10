<?php

namespace App\Api;

use App\Ledger\HoldingPeriod;
use App\Models\Asset;
use App\Models\Parcel;
use App\Support\Decimal;

/**
 * The open parcels of one asset: what is still held, lot by lot.
 */
class ParcelsData
{
    /**
     * The parcels with units left, oldest first. `cost_base` is the cost of
     * the units remaining, not of the whole parcel. A parcel has no id of
     * its own to quote: it is rebuilt whenever the ledger changes.
     *
     * @return array<string, mixed>
     */
    public function for(Asset $asset): array
    {
        $owner = $asset->user;

        return [
            'asset' => Format::asset($asset),
            'parcels' => $asset->parcels()->open()->orderBy('acquired_at')->orderBy('id')->get()->map(function (Parcel $parcel) use ($owner) {
                $cost = $parcel->remainingCostBase();

                return [
                    'acquired_at' => Format::date($parcel->acquired_at, $owner),
                    'units_acquired' => Format::number($parcel->units),
                    'units_remaining' => Format::number($parcel->units_remaining),
                    'cost_base' => Format::aud($cost),
                    'cost_per_unit' => Format::number($cost->div($parcel->units_remaining, Decimal::PRICE_SCALE)),
                    'estimated' => $parcel->estimated,
                    'held_over_12_months' => HoldingPeriod::isLongHeld($parcel->acquired_at, now(), $owner),
                    'transaction_id' => $parcel->transaction_id,
                ];
            })->all(),
        ];
    }
}
