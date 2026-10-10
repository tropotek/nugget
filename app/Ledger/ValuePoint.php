<?php

namespace App\Ledger;

use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * One point of a value series: the AUD value at a time. In a daily series
 * `at` is a date as `Price::day()` makes it, not a moment.
 */
final readonly class ValuePoint
{
    public function __construct(public CarbonImmutable $at, public Decimal $value) {}
}
