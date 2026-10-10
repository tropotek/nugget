<?php

namespace App\Prices;

use App\Support\Decimal;
use InvalidArgumentException;

/**
 * A price read from a source: what one unit of an asset would sell for
 * (`bid`) and cost to buy (`ask`), in AUD.
 */
final readonly class Quote
{
    public function __construct(public Decimal $bid, public Decimal $ask)
    {
        if (! $bid->isPositive() || ! $ask->isPositive()) {
            throw new InvalidArgumentException("A quote needs a bid and an ask above zero, not {$bid} and {$ask}.");
        }
    }

    /**
     * From numbers as an API hands them over: strings, integers or floats.
     */
    public static function of(mixed $bid, mixed $ask): self
    {
        return new self(self::price($bid), self::price($ask));
    }

    private static function price(mixed $value): Decimal
    {
        return match (true) {
            is_float($value) => Decimal::fromFloat($value, Decimal::PRICE_SCALE),
            is_int($value), is_string($value) => Decimal::of($value)->round(Decimal::PRICE_SCALE),
            default => throw new InvalidArgumentException('A price is a number, not '.get_debug_type($value).'.'),
        };
    }
}
