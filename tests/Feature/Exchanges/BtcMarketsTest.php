<?php

namespace Tests\Feature\Exchanges;

use App\Exchanges\Contracts\QuotesPrices;
use App\Exchanges\Drivers\BtcMarkets;
use App\Exchanges\ExchangeDriver;
use App\Models\Exchange;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * The BTC Markets driver against recorded responses of its v3 API.
 */
class BtcMarketsTest extends TestCase
{
    use RefreshDatabase;

    private const BALANCES = 'https://api.btcmarkets.net/v3/accounts/me/balances';

    private const TICKER = 'https://api.btcmarkets.net/v3/markets/BTC-AUD/ticker';

    private BtcMarkets $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->driver = new BtcMarkets(Exchange::factory()->create(['api_key' => 'my-key', 'secret' => 'my-secret']));
    }

    private function recorded(string $name): string
    {
        return file_get_contents(base_path("tests/Fixtures/btcmarkets/{$name}.json"));
    }

    public function test_it_is_a_driver_that_quotes_prices(): void
    {
        $this->assertInstanceOf(ExchangeDriver::class, $this->driver);
        $this->assertInstanceOf(QuotesPrices::class, $this->driver);
        $this->assertSame('BTC Markets', BtcMarkets::label());
    }

    public function test_it_quotes_a_markets_best_bid_and_ask(): void
    {
        Http::fake([self::TICKER => Http::response($this->recorded('ticker-btc-aud'))]);

        $quote = $this->driver->quote('BTC-AUD');

        $this->assertInstanceOf(Decimal::class, $quote->bid);
        $this->assertSame('118440.39', (string) $quote->bid);
        $this->assertSame('118603.28', (string) $quote->ask);
    }

    public function test_a_price_is_asked_for_without_the_key(): void
    {
        Http::fake([self::TICKER => Http::response($this->recorded('ticker-btc-aud'))]);

        $this->driver->quote('BTC-AUD');

        Http::assertSent(fn (Request $request) => $request->url() === self::TICKER
            && $request->method() === 'GET'
            && ! $request->hasHeader('BM-AUTH-APIKEY')
            && ! str_contains(json_encode($request->headers()), 'my-key'));
    }

    public function test_a_small_price_keeps_its_places(): void
    {
        Http::fake(['*/markets/SHIB-AUD/ticker' => Http::response('{"marketId":"SHIB-AUD","bestBid":"0.00001234","bestAsk":"0.00001301","lastPrice":"0.0000125"}')]);

        $quote = $this->driver->quote('SHIB-AUD');

        $this->assertSame('0.00001234', (string) $quote->bid);
        $this->assertSame('0.00001301', (string) $quote->ask);
    }

    public function test_a_market_with_no_bid_or_ask_is_quoted_at_its_last_trade(): void
    {
        Http::fake([self::TICKER => Http::response('{"marketId":"BTC-AUD","bestBid":null,"lastPrice":"118600"}')]);

        $quote = $this->driver->quote('BTC-AUD');

        $this->assertSame('118600', (string) $quote->bid);
        $this->assertSame('118600', (string) $quote->ask);
    }

    public function test_a_market_the_exchange_does_not_have_throws(): void
    {
        // What BTC Markets answers for a market id it does not know.
        Http::fake(['*/markets/NOPE-AUD/ticker' => Http::response('{"code":"InvalidMarketId","message":"invalid marketId"}', 400)]);

        $this->expectException(RequestException::class);
        $this->expectExceptionMessageMatches('/InvalidMarketId/');

        $this->driver->quote('NOPE-AUD');
    }

    public function test_a_ticker_with_no_price_throws(): void
    {
        Http::fake([self::TICKER => Http::response('{"marketId":"BTC-AUD"}')]);

        $this->expectException(InvalidArgumentException::class);

        $this->driver->quote('BTC-AUD');
    }

    public function test_the_market_id_cannot_reach_another_path(): void
    {
        Http::fake(['*' => Http::response($this->recorded('ticker-btc-aud'))]);

        $this->driver->quote('../accounts/me');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.btcmarkets.net/v3/markets/..%2Faccounts%2Fme/ticker');
    }

    public function test_the_api_url_comes_from_config(): void
    {
        config(['nugget.exchanges.btcmarkets.url' => 'https://btcmarkets.test/v3']);
        Http::fake(['https://btcmarkets.test/v3/markets/BTC-AUD/ticker' => Http::response($this->recorded('ticker-btc-aud'))]);

        $this->assertSame('118440.39', (string) $this->driver->quote('BTC-AUD')->bid);
    }

    // Signed calls

    public function test_checking_the_connection_signs_the_call_with_the_key_and_secret(): void
    {
        $this->travelTo('2025-06-30 03:07:30.123');
        Http::fake([self::BALANCES => Http::response('[]')]);
        $driver = new BtcMarkets(Exchange::factory()->create(['api_key' => 'my-key', 'secret' => base64_encode('my-secret')]));

        $driver->checkConnection();

        // The method, the path from the root, and the time in milliseconds.
        $signature = base64_encode(hash_hmac('sha512', 'GET/v3/accounts/me/balances1751252850123', 'my-secret', true));

        Http::assertSent(fn (Request $request) => $request->url() === self::BALANCES
            && $request->method() === 'GET'
            && $request->header('BM-AUTH-APIKEY') === ['my-key']
            && $request->header('BM-AUTH-TIMESTAMP') === ['1751252850123']
            && $request->header('BM-AUTH-SIGNATURE') === [$signature]);
    }

    public function test_the_secret_itself_is_never_sent(): void
    {
        Http::fake([self::BALANCES => Http::response('[]')]);
        $secret = base64_encode('my-secret');

        (new BtcMarkets(Exchange::factory()->create(['secret' => $secret])))->checkConnection();

        Http::assertSent(fn (Request $request) => ! str_contains(json_encode($request->headers()).$request->url().$request->body(), $secret));
    }

    public function test_a_refused_key_throws(): void
    {
        Http::fake([self::BALANCES => Http::response($this->recorded('invalid-api-key'), 401)]);

        $this->expectException(RequestException::class);

        (new BtcMarkets(Exchange::factory()->create(['secret' => base64_encode('my-secret')])))->checkConnection();
    }

    public function test_a_missing_key_or_a_secret_that_is_not_base64_throws_before_any_call(): void
    {
        foreach ([['api_key' => null], ['secret' => null], ['secret' => 'not base64!']] as $bad) {
            try {
                (new BtcMarkets(Exchange::factory()->create($bad)))->checkConnection();
                $this->fail('It should have thrown.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('API key and a secret', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }
}
