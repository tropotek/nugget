<?php

namespace App\Exchanges\Drivers;

use App\Exchanges\Balance;
use App\Exchanges\Contracts\ChecksConnection;
use App\Exchanges\Contracts\ListsMarkets;
use App\Exchanges\Contracts\QuotesPrices;
use App\Exchanges\Contracts\ReportsBalances;
use App\Exchanges\ExchangeDriver;
use App\Exchanges\Market;
use App\Models\Exchange;
use App\Prices\Quote;
use App\Support\Decimal;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use UnexpectedValueException;

/**
 * BTC Markets, by its v3 REST API (https://docs.btcmarkets.net). Amounts
 * come as strings, so nothing passes through a float.
 */
class BtcMarkets implements ChecksConnection, ExchangeDriver, ListsMarkets, QuotesPrices, ReportsBalances
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

    /**
     * Reads the account's balances, the plainest call that needs the key.
     */
    public function checkConnection(): void
    {
        $this->balances();
    }

    /**
     * BTC Markets lists every coin it trades, most of them at zero. A
     * balance has more places than Nugget keeps and is rounded to them.
     */
    public function balances(): array
    {
        $balances = $this->signed('GET', 'accounts/me/balances')->get('accounts/me/balances')->throw()->json();

        if (! is_array($balances) || ! array_is_list($balances)) {
            throw new UnexpectedValueException('BTC Markets did not answer with a list of balances.');
        }

        return array_map(function (mixed $balance) {
            if (! is_array($balance) || ! is_string($balance['assetName'] ?? null) || ! is_string($balance['balance'] ?? null)) {
                throw new UnexpectedValueException('BTC Markets listed a balance with no asset or no amount.');
            }

            $units = Decimal::of($balance['balance'])->round(Decimal::UNITS_SCALE);

            return new Balance(
                $balance['assetName'],
                $units,
                is_string($balance['available'] ?? null) ? Decimal::of($balance['available'])->round(Decimal::UNITS_SCALE) : $units,
            );
        }, $balances);
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

    /**
     * A request for the account's own data, signed with its key and secret.
     *
     * The signature is the base64 of an HMAC-SHA512, keyed with the secret
     * decoded from base64, over the method, the path from the API's root
     * ("/v3/orders"), the time in milliseconds and, for a POST, the JSON body.
     * A query string is not signed.
     *
     * @param  string  $path  as given to the request, with no query string
     */
    private function signed(string $method, string $path, string $body = ''): PendingRequest
    {
        $key = (string) $this->exchange->api_key;
        $secret = base64_decode((string) $this->exchange->secret, true);

        if ($key === '' || $secret === false || $secret === '') {
            throw new RuntimeException('The exchange needs an API key and a secret, and the secret is the base64 text BTC Markets gives.');
        }

        $root = rtrim((string) parse_url(config('nugget.exchanges.btcmarkets.url'), PHP_URL_PATH), '/');
        $timestamp = (string) now()->getTimestampMs();

        return $this->public()->withHeaders([
            'BM-AUTH-APIKEY' => $key,
            'BM-AUTH-TIMESTAMP' => $timestamp,
            'BM-AUTH-SIGNATURE' => base64_encode(hash_hmac('sha512', "{$method}{$root}/{$path}{$timestamp}{$body}", $secret, true)),
        ]);
    }
}
