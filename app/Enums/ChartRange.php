<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How far back a chart reaches. The shortest is drawn from the intraday
 * prices, which are kept for a couple of days; the rest from the day prices.
 */
enum ChartRange: string
{
    case Hours = '48h';
    case Month = '1m';
    case Year = '1y';
    case All = 'all';

    public const DEFAULT = self::Month;

    /**
     * The range named in a query string, or the default when it names none.
     */
    public static function fromInput(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::DEFAULT;
    }

    public function label(): string
    {
        return match ($this) {
            self::Hours => config('nugget.prices.intraday_hours').'h',
            self::Month => '1m',
            self::Year => '1y',
            self::All => 'All',
        };
    }

    public function isIntraday(): bool
    {
        return $this === self::Hours;
    }

    /**
     * Where the range starts when it ends at this moment. Null for all of it.
     */
    public function start(CarbonImmutable $now): ?CarbonImmutable
    {
        return match ($this) {
            self::Hours => $now->subHours(config('nugget.prices.intraday_hours')),
            self::Month => $now->subMonthNoOverflow(),
            self::Year => $now->subYearNoOverflow(),
            self::All => null,
        };
    }
}
