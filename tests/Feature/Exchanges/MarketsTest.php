<?php

namespace Tests\Feature\Exchanges;

use App\Exchanges\Contracts\ListsMarkets;
use App\Exchanges\Drivers\BtcMarkets;
use App\Exchanges\ExchangeDrivers;
use App\Exchanges\Market;
use App\Exchanges\Markets;
use App\Models\Exchange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\PlainDriver;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * The kept list of an exchange's markets, against a recorded response of
 * BTC Markets' public market list.
 */
class MarketsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.btcmarkets.net/v3/markets';

    private Exchange $exchange;

    private Markets $markets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exchange = Exchange::factory()->create();
        $this->markets = $this->app->make(Markets::class);
    }

    private function fakeTheList(): void
    {
        Http::fake([self::URL => Http::response(file_get_contents(base_path('tests/Fixtures/btcmarkets/markets.json')))]);
    }

    /**
     * @param  list<Market>  $markets
     * @return list<string>
     */
    private function ids(array $markets): array
    {
        return array_column($markets, 'id');
    }

    public function test_btc_markets_lists_its_markets(): void
    {
        $this->fakeTheList();

        $markets = (new BtcMarkets($this->exchange))->markets();

        $this->assertInstanceOf(ListsMarkets::class, new BtcMarkets($this->exchange));
        $this->assertCount(51, $markets);
        $this->assertEquals(new Market('AAVE-AUD', 'AAVE', 'AUD'), $markets[0]);
        $this->assertContains('BTC-AUD', $this->ids($markets));
        $this->assertContains('ETH-BTC', $this->ids($markets));
    }

    public function test_the_market_list_is_asked_for_without_the_key(): void
    {
        $this->fakeTheList();

        (new BtcMarkets($this->exchange))->markets();

        Http::assertSent(fn ($request) => $request->url() === self::URL && $request->method() === 'GET' && ! $request->hasHeader('BM-AUTH-APIKEY'));
    }

    public function test_an_answer_that_is_not_a_list_of_markets_throws(): void
    {
        Http::fake([self::URL => Http::response('{"code":"Oops"}')]);

        $this->expectException(UnexpectedValueException::class);

        (new BtcMarkets($this->exchange))->markets();
    }

    public function test_the_markets_come_a_to_z(): void
    {
        Http::fake([self::URL => Http::response('[{"marketId":"XRP-AUD","baseAssetName":"XRP","quoteAssetName":"AUD"},{"marketId":"ADA-AUD","baseAssetName":"ADA","quoteAssetName":"AUD"}]')]);

        $this->assertSame(['ADA-AUD', 'XRP-AUD'], $this->ids($this->markets->for($this->exchange)));
    }

    public function test_only_the_aud_markets_can_price_an_asset(): void
    {
        $this->fakeTheList();

        $inAud = $this->markets->inAud($this->exchange);

        $this->assertCount(44, $inAud);
        $this->assertNotContains('ETH-BTC', $this->ids($inAud));
        $this->assertTrue($this->markets->prices($this->exchange, 'BTC-AUD'));
        $this->assertFalse($this->markets->prices($this->exchange, 'ETH-BTC'));
        $this->assertFalse($this->markets->prices($this->exchange, 'BTC/AUD'));
        $this->assertFalse($this->markets->prices($this->exchange, 'btc-aud'));
    }

    public function test_the_list_is_kept_for_a_day_and_shared_by_accounts_on_the_same_exchange(): void
    {
        $this->fakeTheList();

        $first = $this->markets->for($this->exchange);
        $this->travel(23)->hours();
        $again = $this->markets->for(Exchange::factory()->create());

        Http::assertSentCount(1);
        $this->assertEquals($first, $again);
        $this->assertInstanceOf(Market::class, $again[0]);

        $this->travel(2)->hours();
        $this->markets->for($this->exchange);

        Http::assertSentCount(2);
    }

    public function test_a_kept_list_can_be_dropped(): void
    {
        $this->fakeTheList();

        $this->markets->for($this->exchange);
        $this->markets->forget('btcmarkets');
        $this->markets->for($this->exchange);

        Http::assertSentCount(2);
    }

    public function test_an_exchange_that_cannot_be_reached_has_no_markets_and_is_asked_again(): void
    {
        Log::spy();
        Http::fakeSequence(self::URL)
            ->push('Service unavailable', 503)
            ->push('[{"marketId":"BTC-AUD","baseAssetName":"BTC","quoteAssetName":"AUD"}]');

        $this->assertSame([], $this->markets->for($this->exchange));
        $this->assertNull($this->markets->prices(Exchange::factory()->create(['driver' => 'nothing']), 'BTC-AUD'));
        $this->assertSame(['BTC-AUD'], $this->ids($this->markets->for($this->exchange)));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, "exchange {$this->exchange->id}"));
    }

    public function test_a_driver_that_cannot_list_markets_has_none(): void
    {
        Log::spy();
        $markets = new Markets(new ExchangeDrivers(['plain' => PlainDriver::class]));
        $exchange = Exchange::factory()->create(['driver' => 'plain']);

        $this->assertSame([], $markets->for($exchange));
        $this->assertNull($markets->prices($exchange, 'BTC-AUD'));

        Log::shouldNotHaveReceived('warning');
        Http::assertNothingSent();
    }
}
