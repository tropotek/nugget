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

    /**
     * The moment as a chart wants it: the user's clock time read as UTC, in
     * milliseconds. A chart that draws its axis in UTC then shows the
     * user's dates and times.
     */
    public static function asUtcMs(DateTimeInterface $date, ?User $user = null): int
    {
        return CarbonImmutable::parse(self::of($date, $user)->format('Y-m-d H:i:s'), 'UTC')->getTimestampMs();
    }
}
