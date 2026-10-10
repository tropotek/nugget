<?php

namespace App\Exchanges;

use App\Models\Exchange;
use ccxt\Exchange as Ccxt;
use InvalidArgumentException;

/**
 * Builds the ccxt client for an exchange. Only ccxt's plain synchronous
 * classes are used: its async and websocket classes need versions of
 * packages that Laravel has moved on from.
 *
 * A client is kept for as long as this object lives (a request, or a run of
 * a command), so ccxt's rate limiter sees every call made to an exchange.
 */
class ExchangeClient
{
    /** @var array<int, Ccxt> by exchange id */
    private array $clients = [];

    /**
     * @throws InvalidArgumentException when ccxt has no such driver
     */
    public function for(Exchange $exchange): Ccxt
    {
        return $this->clients[$exchange->id] ??= $this->make($exchange);
    }

    /**
     * Whether the exchange has a call, by ccxt's name for it ("fetchTicker").
     * Not every exchange has every call, so ask before making one.
     */
    public function supports(Exchange $exchange, string $call): bool
    {
        return (bool) ($this->for($exchange)->has[$call] ?? false);
    }

    protected function make(Exchange $exchange): Ccxt
    {
        if (! in_array($exchange->driver, Ccxt::$exchanges, true)) {
            throw new InvalidArgumentException("ccxt has no driver called '{$exchange->driver}'.");
        }

        $class = '\\ccxt\\'.$exchange->driver;

        return new $class([
            'apiKey' => $exchange->api_key,
            'secret' => $exchange->secret,
            'enableRateLimit' => true,
            'timeout' => 10000,
        ]);
    }
}
