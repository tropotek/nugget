<?php

namespace Tests\Fakes;

use App\Exchanges\ExchangeClient;
use App\Models\Exchange;
use ccxt\Exchange as Ccxt;

/**
 * An exchange client that hands every exchange the same fake ccxt.
 */
class FakeExchangeClient extends ExchangeClient
{
    public function __construct(public FakeCcxt $ccxt = new FakeCcxt) {}

    protected function make(Exchange $exchange): Ccxt
    {
        return $this->ccxt;
    }
}
