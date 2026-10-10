<?php

namespace App\Ledger\Matching;

use App\Models\Parcel;
use App\Models\Transaction;

/**
 * Decides which parcels a sale uses up first.
 */
interface MatchingStrategy
{
    /**
     * The open parcels of the sale's asset, in the order the sale should use them.
     *
     * @param  list<Parcel>  $parcels  oldest first
     * @return list<Parcel>
     */
    public function order(array $parcels, Transaction $sale): array;
}
