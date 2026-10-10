<?php

namespace App\Exchanges\Drivers;

use App\Exchanges\Contracts\QuotesPrices;
use App\Exchanges\ExchangeDriver;
use App\Models\Exchange;
use App\Prices\Quote;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * BTC Markets, by its v3 REST API (https://docs.btcmarkets.net). Amounts
 * come as strings, so nothing passes through a float.
 */
class BtcMarkets implements ExchangeDriver, QuotesPrices
{
    public function __construct(private readonly Exchange $exchange) {}

    public static function label(): string
    {
        return 'BTC Markets';
    }

    /**
     * A market with nobody bidding or asking is quoted at its last trade.
     */
    public function quote(string $market): Quote
    {
        $ticker = $this->public()->get('markets/'.rawurlencode($market).'/ticker')->throw()->json();

        return Quote::of(
            $ticker['bestBid'] ?? $ticker['lastPrice'] ?? null,
            $ticker['bestAsk'] ?? $ticker['lastPrice'] ?? null,
        );
    }

    /**
     * A request that needs no key: market data.
     */
    private function public(): PendingRequest
    {
        return Http::baseUrl(config('nugget.exchanges.btcmarkets.url'))->acceptJson()->timeout(10);
    }
}
