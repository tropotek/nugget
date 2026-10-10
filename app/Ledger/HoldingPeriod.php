<?php

namespace App\Ledger;

use App\Models\User;
use App\Support\LocalTime;
use DateTimeInterface;

class HoldingPeriod
{
    /**
     * Held longer than 12 months, by the owner's calendar, not counting the
     * day of acquisition or the day of sale: a parcel acquired on 10 March is
     * long held when sold on or after 11 March the next year.
     */
    public static function isLongHeld(DateTimeInterface $acquiredAt, DateTimeInterface $soldAt, User $owner): bool
    {
        $acquired = LocalTime::of($acquiredAt, $owner)->startOfDay();
        $sold = LocalTime::of($soldAt, $owner)->startOfDay();

        return $sold->greaterThan($acquired->addYearNoOverflow());
    }
}
