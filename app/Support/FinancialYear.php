<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * An Australian financial year, 1 July to 30 June, named by the year it ends
 * in: 2025 is 2024–25. When it starts and ends depends on the timezone.
 */
final readonly class FinancialYear
{
    public function __construct(public int $year) {}

    /**
     * The financial year a moment falls in, by the calendar of a timezone.
     */
    public static function containing(DateTimeInterface $moment, string $timezone): self
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($timezone);

        return new self($local->month >= 7 ? $local->year + 1 : $local->year);
    }

    /**
     * Midnight at the start of 1 July.
     */
    public function start(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::create($this->year - 1, 7, 1, 0, 0, 0, $timezone);
    }

    /**
     * Midnight at the end of 30 June: the first moment of the next year, which is not in this one.
     */
    public function end(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, 7, 1, 0, 0, 0, $timezone);
    }

    public function label(): string
    {
        return ($this->year - 1).'–'.substr((string) $this->year, -2);
    }
}
