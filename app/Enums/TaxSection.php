<?php

namespace App\Enums;

/**
 * A section of the capital gains report: crypto, and one for everything
 * else. An asset's own class is kept, so metals could be split out later.
 */
enum TaxSection: string
{
    case Crypto = 'crypto';
    case Other = 'other';

    public static function of(AssetClass $class): self
    {
        return $class === AssetClass::Crypto ? self::Crypto : self::Other;
    }

    public function label(): string
    {
        return match ($this) {
            self::Crypto => 'Crypto',
            self::Other => 'Other assets',
        };
    }
}
