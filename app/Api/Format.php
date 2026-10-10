<?php

namespace App\Api;

use App\Models\Asset;
use App\Models\User;
use App\Support\Decimal;
use App\Support\LocalTime;
use DateTimeInterface;

/**
 * How the API writes its values. An amount is a decimal string and never a
 * JSON number, which a reader would turn into a float. A date is ISO 8601 in
 * the user's own timezone, with its offset.
 */
class Format
{
    /**
     * An AUD total, to the cent: "1234.50".
     */
    public static function aud(?Decimal $amount): ?string
    {
        return $amount?->toFixed(Decimal::AUD_SCALE);
    }

    /**
     * A number of units, a unit price or a percentage, with the places it has: "0.05".
     */
    public static function number(?Decimal $amount): ?string
    {
        return $amount === null ? null : (string) $amount;
    }

    /**
     * "2025-03-03T13:00:00+11:00".
     */
    public static function date(?DateTimeInterface $moment, User $user): ?string
    {
        return $moment === null ? null : LocalTime::of($moment, $user)->toIso8601String();
    }

    /**
     * An asset as it is named wherever it appears.
     *
     * @return array<string, mixed>
     */
    public static function asset(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'symbol' => $asset->symbol,
            'class' => $asset->class->value,
            'unit' => $asset->unit,
        ];
    }
}
