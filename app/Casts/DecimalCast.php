<?php

namespace App\Casts;

use App\Support\Decimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Stores a Decimal as a string with a fixed number of decimal places, rounded
 * to fit, and reads it back as a Decimal.
 *
 * The column must be a string column. SQLite turns a value in a decimal or
 * numeric column into a float, which is what this type exists to avoid.
 *
 * @implements CastsAttributes<Decimal, Decimal|string|int>
 */
class DecimalCast implements CastsAttributes
{
    public function __construct(private readonly int $scale) {}

    /**
     * The cast for a number of units of an asset.
     */
    public static function units(): string
    {
        return self::scale(Decimal::UNITS_SCALE);
    }

    /**
     * The cast for the AUD price of one unit.
     */
    public static function price(): string
    {
        return self::scale(Decimal::PRICE_SCALE);
    }

    /**
     * The cast for an AUD total.
     */
    public static function aud(): string
    {
        return self::scale(Decimal::AUD_SCALE);
    }

    public static function scale(int $scale): string
    {
        return self::class.':'.$scale;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Decimal
    {
        if (is_float($value)) {
            throw new LogicException($model::class."::\${$key} came back from the database as a float. Make it a string column.");
        }

        return $value === null ? null : Decimal::of($value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Decimal::of($value)->toFixed($this->scale);
    }
}
