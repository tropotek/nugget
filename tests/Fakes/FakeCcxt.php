<?php

namespace Tests\Fakes;

use ccxt\Exchange;
use ccxt\NetworkError;
use Throwable;

/**
 * A ccxt exchange that answers from what it is given and never touches the
 * network. `has` is ccxt's own map of the calls an exchange supports.
 */
class FakeCcxt extends Exchange
{
    /** @var array<string, array<string, mixed>|Throwable> by market */
    public array $fakeTickers = [];

    /** @var list<string> the markets a ticker was asked for, in order */
    public array $askedFor = [];

    /**
     * @param  array<string, bool|string>  $has
     */
    public function __construct(array $has = ['fetchTicker' => true])
    {
        parent::__construct();

        $this->has = $has;
    }

    /**
     * @param  array<string, mixed>|Throwable  $ticker  a unified ticker, or what the call throws
     */
    public function ticker(string $market, array|Throwable $ticker): static
    {
        $this->fakeTickers[$market] = $ticker;

        return $this;
    }

    public function fetch_ticker(string $symbol, $params = [])
    {
        $this->askedFor[] = $symbol;
        $ticker = $this->fakeTickers[$symbol] ?? new NetworkError("fake does not have a ticker for {$symbol}");

        return $ticker instanceof Throwable ? throw $ticker : $ticker;
    }
}
