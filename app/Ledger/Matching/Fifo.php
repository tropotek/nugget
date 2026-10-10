<?php

namespace App\Ledger\Matching;

use App\Models\Transaction;

/**
 * First in, first out: the oldest parcel first.
 */
class Fifo implements MatchingStrategy
{
    public function order(array $parcels, Transaction $sale): array
    {
        return $parcels;
    }
}
