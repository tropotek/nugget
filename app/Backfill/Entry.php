<?php

namespace App\Backfill;

use App\Enums\TransactionType;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * One ledger transaction read from an exchange's transaction report, before
 * it is stored: so many units of a coin bought, sold, moved or rewarded at a
 * moment.
 *
 * A trade for AUD knows its price, fee and total. A coin swapped for another
 * coin, and a reward, do not: `total` is null and the backfill values them
 * in AUD from a price of that day. `pairSymbol` and `pairUnits` are the other
 * side of a swap, which is what it is valued by when it is the priced coin.
 */
final readonly class Entry
{
    /**
     * @param  string  $id  the report's own id for the row that moved the coin
     * @param  string  $symbol  the coin, as the exchange names it
     * @param  Decimal  $units  how many, always above zero
     * @param  Decimal|null  $unitPrice  AUD for one unit, when the report gives it
     * @param  Decimal|null  $fee  AUD, when the report gives it
     * @param  Decimal|null  $total  the AUD cost with the fee, or the proceeds less it; null when it has to be valued
     * @param  bool  $airdrop  a reward that came at no cost on purpose
     */
    public function __construct(
        public string $id,
        public string $symbol,
        public TransactionType $type,
        public CarbonImmutable $at,
        public Decimal $units,
        public ?Decimal $unitPrice = null,
        public ?Decimal $fee = null,
        public ?Decimal $total = null,
        public ?string $pairSymbol = null,
        public ?Decimal $pairUnits = null,
        public bool $airdrop = false,
    ) {}

    public function isSwap(): bool
    {
        return $this->pairSymbol !== null;
    }

    /**
     * The change this makes to the units held: negative when units leave.
     */
    public function signedUnits(): Decimal
    {
        return $this->type->removesUnits() ? $this->units->negate() : $this->units;
    }
}
