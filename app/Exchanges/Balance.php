<?php

namespace App\Exchanges;

use App\Support\Decimal;

/**
 * What an account holds of one symbol on an exchange, as the exchange
 * reports it: `units` in all, of which `available` is not tied up in open
 * orders. The symbol is the exchange's own ("BTC", "AUD").
 */
final readonly class Balance
{
    public function __construct(public string $symbol, public Decimal $units, public Decimal $available) {}
}
