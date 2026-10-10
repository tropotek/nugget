<?php

namespace App\Exchanges;

/**
 * Nugget's own client for one exchange's API, written against that API and
 * nothing else. A driver is made for one of a user's exchanges and holds its
 * key and secret.
 *
 * What a driver can do is said by the interfaces in App\Exchanges\Contracts
 * that it implements. Not every exchange offers everything, so a caller
 * checks for the interface before it calls:
 *
 *     if ($driver instanceof QuotesPrices) { $quote = $driver->quote('BTC-AUD'); }
 *
 * A call that fails throws. Market ids are the exchange's own ("BTC-AUD" on
 * BTC Markets).
 */
interface ExchangeDriver
{
    /**
     * The exchange's name, for a list of drivers to choose from.
     */
    public static function label(): string;
}
