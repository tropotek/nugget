<?php

namespace Tests\Feature\Prices;

use App\Exchanges\ExchangeClient;
use App\Models\Asset;
use App\Models\Exchange;
use App\Prices\ExchangePriceSource;
use ccxt\ExchangeError;
use ccxt\NetworkError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeCcxt;
use Tests\Fakes\FakeExchangeClient;
use Tests\TestCase;

class ExchangePriceSourceTest extends TestCase
{
    use RefreshDatabase;

    private FakeCcxt $ccxt;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ccxt = new FakeCcxt;
        $this->app->instance(ExchangeClient::class, new FakeExchangeClient($this->ccxt));
        $this->asset = Asset::factory()->onExchange(Exchange::factory()->create(), 'BTC/AUD')->create();
    }

    /**
     * The ticker ccxt gave for BTC/AUD on BTC Markets, as recorded.
     *
     * @return array<string, mixed>
     */
    private function recorded(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/ccxt/btcmarkets-ticker-btc-aud.json')), true);
    }

    private function source(): ExchangePriceSource
    {
        return $this->app->make(ExchangePriceSource::class);
    }

    public function test_it_quotes_the_bid_and_ask_of_the_assets_market(): void
    {
        $this->ccxt->ticker('BTC/AUD', $this->recorded());

        $quote = $this->source()->quote($this->asset);

        $this->assertSame('118497.22', (string) $quote->bid);
        $this->assertSame('118679.05', (string) $quote->ask);
        $this->assertSame(['BTC/AUD'], $this->ccxt->askedFor);
    }

    public function test_a_ticker_with_no_bid_or_ask_is_quoted_at_its_last_price(): void
    {
        $this->ccxt->ticker('BTC/AUD', ['bid' => null, 'ask' => null, 'last' => 118600] + $this->recorded());

        $quote = $this->source()->quote($this->asset);

        $this->assertSame('118600', (string) $quote->bid);
        $this->assertSame('118600', (string) $quote->ask);
    }

    public function test_a_small_price_keeps_its_places(): void
    {
        $this->ccxt->ticker('BTC/AUD', ['bid' => 1.234E-5, 'ask' => 0.00001301]);

        $quote = $this->source()->quote($this->asset);

        $this->assertSame('0.00001234', (string) $quote->bid);
        $this->assertSame('0.00001301', (string) $quote->ask);
    }

    public function test_an_exchange_with_no_ticker_call_is_not_asked(): void
    {
        Log::spy();
        $this->ccxt->has = ['fetchTicker' => false];
        $this->ccxt->ticker('BTC/AUD', $this->recorded());

        $this->assertNull($this->source()->quote($this->asset));
        $this->assertSame([], $this->ccxt->askedFor);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'has no ticker'));
    }

    public function test_an_emulated_ticker_call_counts_as_one(): void
    {
        $this->ccxt->has = ['fetchTicker' => 'emulated'];
        $this->ccxt->ticker('BTC/AUD', $this->recorded());

        $this->assertNotNull($this->source()->quote($this->asset));
    }

    public function test_a_failed_call_gives_no_quote_and_is_logged_not_thrown(): void
    {
        Log::spy();

        foreach ([new NetworkError('btcmarkets GET timed out'), new ExchangeError('btcmarkets {"code":"MarketNotFound"}')] as $error) {
            $this->ccxt->ticker('BTC/AUD', $error);

            $this->assertNull($this->source()->quote($this->asset));
        }

        Log::shouldHaveReceived('warning')->twice()->withArgs(fn (string $message) => str_contains($message, "asset {$this->asset->id} (Bitcoin)")
            && str_contains($message, 'BTC/AUD')
            && str_contains($message, 'btcmarkets'));
    }

    public function test_a_ticker_with_no_price_at_all_gives_no_quote(): void
    {
        Log::spy();

        foreach ([['bid' => null, 'ask' => null, 'last' => null], ['bid' => 0, 'ask' => 0], ['bid' => -1.0, 'ask' => 5.0], []] as $ticker) {
            $this->ccxt->ticker('BTC/AUD', $ticker);

            $this->assertNull($this->source()->quote($this->asset));
        }

        Log::shouldHaveReceived('warning')->times(4);
    }

    public function test_an_asset_with_no_exchange_gives_no_quote(): void
    {
        Log::spy();

        $this->assertNull($this->source()->quote(Asset::factory()->metal()->create()));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_an_unknown_driver_gives_no_quote(): void
    {
        Log::spy();
        $this->app->forgetInstance(ExchangeClient::class);
        $asset = Asset::factory()->onExchange(Exchange::factory()->create(['driver' => 'notanexchange']), 'BTC/AUD')->create();

        $this->assertNull($this->source()->quote($asset));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, "no driver called 'notanexchange'"));
    }

    public function test_an_inactive_exchange_still_prices_its_assets(): void
    {
        $this->asset->exchange->update(['active' => false]);
        $this->ccxt->ticker('BTC/AUD', $this->recorded());

        $this->assertNotNull($this->source()->quote($this->asset->fresh()));
    }
}
