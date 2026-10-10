<?php

namespace App\Ledger;

use App\Models\Transaction;
use App\Support\Decimal;
use App\Support\LocalTime;
use RuntimeException;

/**
 * A transaction takes more units out of a holding than it had at the time.
 */
class InsufficientUnits extends RuntimeException
{
    public static function for(Transaction $transaction, Decimal $held): self
    {
        $asset = $transaction->asset;
        $type = strtolower($transaction->type->label());
        $date = LocalTime::of($transaction->occurred_at, $asset->user)->format('j M Y');

        return new self(
            "{$asset->name}: the {$type} of {$transaction->units->abs()} {$asset->unit} on {$date} is more than the {$held} {$asset->unit} held.",
        );
    }
}
