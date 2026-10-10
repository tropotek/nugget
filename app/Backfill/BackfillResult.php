<?php

namespace App\Backfill;

/**
 * What a backfill did, for the command to print.
 */
final class BackfillResult
{
    /** @var array<string, int> the transactions stored, by type */
    public array $added = [];

    /** How many entries were already in the ledger and were passed over. */
    public int $alreadyThere = 0;

    /** How many estimated transactions the real history replaced. */
    public int $estimatesRemoved = 0;

    /** How many trades the sync had fetched that the report also has. */
    public int $syncedRemoved = 0;

    /** @var list<string> the assets it had to make */
    public array $assetsCreated = [];

    /** @var list<string> deposits of coins with no cost known, for the owner to fill in */
    public array $depositsWithoutCost = [];

    /** How many transactions (the two sides of a swap, a reward) were valued from the nearest trade in the report, for want of a price that day. */
    public int $valuedFromTheReport = 0;

    /** How many could not be valued at all, and were stored at $0. */
    public int $notValued = 0;

    /** @var array<string, array{before: array{string, string}, after: array{string, string}}> units and cost base, by asset name */
    public array $summary = [];

    /** @var list<string> */
    public array $warnings = [];

    public function total(): int
    {
        return array_sum($this->added);
    }
}
