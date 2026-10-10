<?php

namespace App\Exchanges;

use App\Enums\TransactionType;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * One trade on an account, as the exchange reports it.
 *
 * `units` of the market's base were bought or sold at `price` each, in the
 * market's `quote` currency. The `fee` is in `feeSymbol`, which is the quote
 * on some exchanges and the coin itself on others.
 */
final readonly class Trade
{
    /**
     * @param  string  $id  the exchange's own id for the trade
     * @param  string  $market  the exchange's market id ("BTC-AUD")
     * @param  TransactionType  $type  Buy or Sell
     */
    public function __construct(
        public string $id,
        public string $market,
        public string $quote,
        public TransactionType $type,
        public CarbonImmutable $at,
        public Decimal $units,
        public Decimal $price,
        public Decimal $fee,
        public string $feeSymbol,
    ) {}
}
