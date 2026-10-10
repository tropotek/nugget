<?php

namespace App\Exchanges\Contracts;

use App\Prices\Quote;
use Throwable;

/**
 * An exchange that gives the current price of its markets.
 */
interface QuotesPrices
{
    /**
     * The bid and ask of a market now, in AUD.
     *
     * @throws Throwable when the exchange cannot be reached or has no such market
     */
    public function quote(string $market): Quote;
}
