<?php

namespace Tests\Feature\Prices;

use App\Exchanges\ExchangeClient;
use App\Models\Asset;
use App\Models\Exchange;
use App\Prices\ExchangePriceSource;
use App\Prices\FeedPriceSource;
use App\Prices\PriceSource;
use App\Prices\PriceSources;
use App\Prices\Quote;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\Fakes\FakeCcxt;
use Tests\Fakes\FakeExchangeClient;
use Tests\TestCase;

class PriceSourcesTest extends TestCase
{
    use RefreshDatabase;

    private function sources(): PriceSources
    {
        return $this->app->make(PriceSources::class);
    }

    public function test_an_asset_is_priced_by_the_source_it_names(): void
    {
        $onExchange = Asset::factory()->onExchange(Exchange::factory()->create(), 'BTC/AUD')->create();

        $this->assertInstanceOf(PriceSource::class, $this->sources());
        $this->assertInstanceOf(ExchangePriceSource::class, $this->sources()->for($onExchange));
        $this->assertInstanceOf(FeedPriceSource::class, $this->sources()->for(Asset::factory()->metal()->create()));
        $this->assertNull($this->sources()->for(Asset::factory()->other()->create()));
    }

    public function test_it_quotes_an_asset_from_its_exchange_or_from_the_feed(): void
    {
        $ccxt = (new FakeCcxt)->ticker('BTC/AUD', ['bid' => 118497.22, 'ask' => 118679.05]);
        $this->app->instance(ExchangeClient::class, new FakeExchangeClient($ccxt));
        Http::fake(['*/XAU/AUD' => Http::response(file_get_contents(base_path('tests/Fixtures/swissquote/xau-aud.json')))]);

        $bitcoin = Asset::factory()->onExchange(Exchange::factory()->create(), 'BTC/AUD')->create();
        $gold = Asset::factory()->metal()->create();

        $this->assertSame('118497.22', (string) $this->sources()->quote($bitcoin)->bid);
        $this->assertSame('6002.774', (string) $this->sources()->quote($gold)->bid);
    }

    public function test_an_asset_with_no_price_source_has_no_quote_and_nothing_is_logged(): void
    {
        Log::spy();
        Http::fake();

        $this->assertNull($this->sources()->quote(Asset::factory()->other()->create()));

        Http::assertNothingSent();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_quote_reads_numbers_as_an_api_gives_them(): void
    {
        $quote = Quote::of(118497.22, '118679.050000001');

        $this->assertInstanceOf(Decimal::class, $quote->bid);
        $this->assertSame('118497.22', (string) $quote->bid);
        $this->assertSame('118679.05', (string) $quote->ask);
        $this->assertSame('7', (string) Quote::of(7, 8)->bid);
    }

    public function test_a_quote_needs_a_bid_and_an_ask_above_zero(): void
    {
        foreach ([[0, 1], [1, 0], [-1, 1], [null, 1], ['abc', 1], [true, 1], [NAN, 1]] as [$bid, $ask]) {
            try {
                Quote::of($bid, $ask);
                $this->fail('Expected an exception for '.var_export($bid, true));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
