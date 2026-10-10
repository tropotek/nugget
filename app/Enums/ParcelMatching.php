<?php

namespace App\Enums;

/**
 * The order a sale uses up the parcels of an asset.
 */
enum ParcelMatching: string
{
    case Fifo = 'fifo';
    case LowestGain = 'lowest_gain';

    public function label(): string
    {
        return match ($this) {
            self::Fifo => 'First in, first out',
            self::LowestGain => 'Lowest gain first',
        };
    }
}
