<?php

namespace Tests\Feature\Prices;

use App\Exchanges\ExchangeDrivers;
use App\Models\Asset;
use App\Models\Exchange;
use App\Prices\ExchangePriceSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\PlainDriver;
use Tests\TestCase;

class ExchangePriceSourceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.btcmarkets.net/v3/markets/BTC-AUD/ticker';

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->asset = Asset::factory()->onExchange(Exchange::factory()->create(), 'BTC-AUD')->create();
    }

    private function recorded(): string
    {
        return file_get_contents(base_path('tests/Fixtures/btcmarkets/ticker-btc-aud.json'));
    }

    private function source(): ExchangePriceSource
    {
        return $this->app->make(ExchangePriceSource::class);
    }

    public function test_it_quotes_the_assets_market_from_its_exchanges_driver(): void
    {
        Http::fake([self::URL => Http::response($this->recorded())]);

        $quote = $this->source()->quote($this->asset);

        $this->assertSame('118440.39', (string) $quote->bid);
        $this->assertSame('118603.28', (string) $quote->ask);
        Http::assertSentCount(1);
    }

    public function test_a_failed_call_gives_no_quote_and_is_logged_not_thrown(): void
    {
        Log::spy();
        Http::fake([self::URL => Http::sequence()
            ->push('{"code":"InvalidMarketId","message":"invalid marketId"}', 400)
            ->push('Service unavailable', 503)
            ->pushResponse(fn () => throw new ConnectionException('Connection timed out'))]);

        $this->assertNull($this->source()->quote($this->asset));
        $this->assertNull($this->source()->quote($this->asset));
        $this->assertNull($this->source()->quote($this->asset));

        Log::shouldHaveReceived('warning')->times(3)->withArgs(fn (string $message) => str_contains($message, "asset {$this->asset->id} (Bitcoin)")
            && str_contains($message, 'BTC-AUD'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'InvalidMarketId'));
    }

    public function test_a_ticker_with_no_price_gives_no_quote(): void
    {
        Log::spy();
        Http::fake([self::URL => Http::sequence()->push('{"marketId":"BTC-AUD"}')->push('{"bestBid":"0","bestAsk":"0"}')->push('[]')]);

        $this->assertNull($this->source()->quote($this->asset));
        $this->assertNull($this->source()->quote($this->asset));
        $this->assertNull($this->source()->quote($this->asset));
        Log::shouldHaveReceived('warning')->times(3);
    }

    public function test_a_driver_that_gives_no_prices_is_not_asked(): void
    {
        Log::spy();
        Http::fake();
        $this->app->instance(ExchangeDrivers::class, new ExchangeDrivers(['plain' => PlainDriver::class]));
        $asset = Asset::factory()->onExchange(Exchange::factory()->create(['driver' => 'plain']), 'BTC-AUD')->create();

        $this->assertNull($this->source()->quote($asset));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'the plain driver gives no prices'));
    }

    public function test_an_exchange_with_no_driver_gives_no_quote(): void
    {
        Log::spy();
        Http::fake();
        $asset = Asset::factory()->onExchange(Exchange::factory()->create(['driver' => 'kraken']), 'XBT/AUD')->create();

        $this->assertNull($this->source()->quote($asset));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, "no exchange driver called 'kraken'"));
    }

    public function test_an_asset_with_no_exchange_gives_no_quote(): void
    {
        Log::spy();
        Http::fake();

        $this->assertNull($this->source()->quote(Asset::factory()->metal()->create()));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_inactive_exchange_still_prices_its_assets(): void
    {
        Http::fake([self::URL => Http::response($this->recorded())]);
        $this->asset->exchange->update(['active' => false]);

        $this->assertNotNull($this->source()->quote($this->asset->fresh()));
    }
}
