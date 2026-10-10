<?php

namespace App\Exchanges\Drivers;

use App\Exchanges\Contracts\ListsMarkets;
use App\Exchanges\Contracts\QuotesPrices;
use App\Exchanges\ExchangeDriver;
use App\Exchanges\Market;
use App\Models\Exchange;
use App\Prices\Quote;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use UnexpectedValueException;

/**
 * BTC Markets, by its v3 REST API (https://docs.btcmarkets.net). Amounts
 * come as strings, so nothing passes through a float.
 */
class BtcMarkets implements ExchangeDriver, ListsMarkets, QuotesPrices
{
    /** @var array<string, Quote> the tickers read so far, by market */
    private array $quotes = [];

    public function __construct(private readonly Exchange $exchange) {}

    public static function label(): string
    {
        return 'BTC Markets';
    }

    /**
     * A market with nobody bidding or asking is quoted at its last trade. A
     * market is read once in the life of the driver, so two assets on one
     * market cost one call.
     */
    public function quote(string $market): Quote
    {
        return $this->quotes[$market] ??= $this->ticker($market);
    }

    /**
     * Every market, whatever its status: one that is offline may still be held.
     */
    public function markets(): array
    {
        $markets = $this->public()->get('markets')->throw()->json();

        if (! is_array($markets) || ! array_is_list($markets)) {
            throw new UnexpectedValueException('BTC Markets did not answer with a list of markets.');
        }

        return array_map(fn (mixed $market) => match (true) {
            is_array($market) && is_string($market['marketId'] ?? null) => new Market(
                $market['marketId'],
                (string) ($market['baseAssetName'] ?? ''),
                (string) ($market['quoteAssetName'] ?? ''),
            ),
            default => throw new UnexpectedValueException('BTC Markets listed a market with no id.'),
        }, $markets);
    }

    private function ticker(string $market): Quote
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
