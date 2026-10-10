<?php

namespace App\Enums;

use App\Ledger\Matching\Fifo;
use App\Ledger\Matching\LowestGain;
use App\Ledger\Matching\MatchingStrategy;

/**
 * The order a sale uses up the parcels of an asset.
 */
enum ParcelMatching: string
{
    case Fifo = 'fifo';
    case LowestGain = 'lowest_gain';

    public function strategy(): MatchingStrategy
    {
        return match ($this) {
            self::Fifo => new Fifo,
            self::LowestGain => new LowestGain,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Fifo => 'First in, first out',
            self::LowestGain => 'Lowest gain first',
        };
    }
}
