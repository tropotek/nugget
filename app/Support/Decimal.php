<?php

namespace App\Support;

use BcMath\Number;
use InvalidArgumentException;
use JsonSerializable;
use RoundingMode;
use Stringable;
use TypeError;

/**
 * An exact decimal number, for money and units. It is never a float and never
 * made from one.
 *
 * Adding, subtracting and multiplying are exact. Dividing and rounding take
 * the number of decimal places to keep, and round a half away from zero.
 * A Decimal does not change: every operation returns a new one.
 */
final class Decimal implements JsonSerializable, Stringable
{
    /** Decimal places kept for a number of units of an asset. */
    public const UNITS_SCALE = 8;

    /** Decimal places kept for the AUD price of one unit. Two would not hold the price of a cheap coin. */
    public const PRICE_SCALE = 8;

    /** Decimal places kept for an AUD total. */
    public const AUD_SCALE = 2;

    private function __construct(private readonly Number $number) {}

    /**
     * A float is in the signature only so that it is refused here: PHP would
     * otherwise turn it into a string without a word.
     */
    public static function of(self|string|int|float $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_float($value)) {
            throw new TypeError('A Decimal cannot be made from a float. Pass the number as a string.');
        }

        if (is_string($value) && ! preg_match('/^-?\d+(\.\d+)?$/D', $value)) {
            throw new InvalidArgumentException("'{$value}' is not a plain decimal number.");
        }

        return new self(new Number($value));
    }

    /**
     * A number that an outside API hands over as a float, rounded to a number
     * of places. This is the only way in for a float, and it is for reading
     * such a number, never for the result of float arithmetic.
     */
    public static function fromFloat(float $value, int $scale): self
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('A Decimal cannot be made from a float that is not a finite number.');
        }

        return self::of(sprintf("%.{$scale}F", $value));
    }

    public static function zero(): self
    {
        return new self(new Number(0));
    }

    /**
     * @param  iterable<self|string|int>  $values
     */
    public static function sum(iterable $values): self
    {
        $sum = self::zero();

        foreach ($values as $value) {
            $sum = $sum->add($value);
        }

        return $sum;
    }

    public function add(self|string|int|float $other): self
    {
        return new self($this->number->add(self::of($other)->number));
    }

    public function sub(self|string|int|float $other): self
    {
        return new self($this->number->sub(self::of($other)->number));
    }

    public function mul(self|string|int|float $other): self
    {
        return new self($this->number->mul(self::of($other)->number));
    }

    /**
     * BCMath cuts a quotient off rather than rounding it, so one more place is
     * worked out and the last is rounded.
     */
    public function div(self|string|int|float $other, int $scale): self
    {
        return new self($this->number->div(self::of($other)->number, $scale + 1))->round($scale);
    }

    public function round(int $scale): self
    {
        return new self($this->number->round($scale, RoundingMode::HalfAwayFromZero));
    }

    public function negate(): self
    {
        return new self($this->number->mul(-1));
    }

    public function abs(): self
    {
        return $this->isNegative() ? $this->negate() : $this;
    }

    /**
     * -1, 0 or 1 as this is less than, equal to or greater than the other.
     */
    public function compare(self|string|int|float $other): int
    {
        return $this->number->compare(self::of($other)->number);
    }

    public function equals(self|string|int|float $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function greaterThan(self|string|int|float $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(self|string|int|float $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isZero(): bool
    {
        return $this->compare(0) === 0;
    }

    public function isPositive(): bool
    {
        return $this->compare(0) > 0;
    }

    public function isNegative(): bool
    {
        return $this->compare(0) < 0;
    }

    /**
     * Rounded to exactly this many decimal places, as stored: "1.5" to 2 places is "1.50".
     */
    public function toFixed(int $scale): string
    {
        $rounded = $this->round($scale);
        [$whole, $fraction] = explode('.', $rounded->number->value.'.');

        // A small negative rounds to zero, which has no sign.
        if ($rounded->isZero()) {
            $whole = '0';
        }

        return $scale > 0 ? $whole.'.'.str_pad($fraction, $scale, '0') : $whole;
    }

    /**
     * Rounded for reading, with thousands separators: "1,234,567.89".
     */
    public function format(int $scale = self::AUD_SCALE): string
    {
        [$whole, $fraction] = explode('.', $this->toFixed($scale).'.');

        $sign = str_starts_with($whole, '-') ? '-' : '';
        $whole = strrev(implode(',', str_split(strrev(ltrim($whole, '-')), 3)));

        return $sign.$whole.($scale > 0 ? '.'.$fraction : '');
    }

    /**
     * A unit price for reading: two places, or as many as it has, up to
     * eight, when it is under a dollar. A cheap coin is "0.00001234", not "0.00".
     */
    public function formatPrice(): string
    {
        if (! $this->abs()->lessThan(1)) {
            return $this->format(self::AUD_SCALE);
        }

        $exact = (string) $this->round(self::PRICE_SCALE);
        $places = strlen((string) strstr($exact, '.')) - 1;

        return $this->format(max(self::AUD_SCALE, $places));
    }

    /**
     * The exact value with no padding: "1.50" prints as "1.5".
     */
    public function __toString(): string
    {
        if ($this->isZero()) {
            return '0';
        }

        $value = $this->number->value;

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    public function jsonSerialize(): string
    {
        return (string) $this;
    }
}
