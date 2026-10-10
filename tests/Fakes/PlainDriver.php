<?php

namespace Tests\Fakes;

use App\Exchanges\ExchangeDriver;
use App\Models\Exchange;

/**
 * A driver for an exchange that offers nothing: no prices, no balances, no trades.
 */
class PlainDriver implements ExchangeDriver
{
    public function __construct(public readonly Exchange $exchange) {}

    public static function label(): string
    {
        return 'A plain exchange';
    }
}
