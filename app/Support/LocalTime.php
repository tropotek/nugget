<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Dates are stored in UTC and shown in the timezone of the user reading them.
 */
class LocalTime
{
    /**
     * The date in the user's timezone: the given user, or else whoever is signed in.
     */
    public static function of(DateTimeInterface $date, ?User $user = null): CarbonImmutable
    {
        $user ??= auth()->user();

        return CarbonImmutable::instance($date)->setTimezone($user->timezone ?? User::DEFAULT_TIMEZONE);
    }
}
