<?php

namespace Tests\Feature\Prices;

use App\Enums\PriceResolution;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Price;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The run is at 03:07:30 UTC on 30 June 2025, so its prices are stored at 03:07:00.
 */
class FetchPricesCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Exchange $exchange;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-06-30 03:07:30');
        Http::preventStrayRequests();

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->exchange = Exchange::factory()->for($this->user)->create();
    }

    private function ticker(string $bid, string $ask): string
    {
        return json_encode(['bestBid' => $bid, 'bestAsk' => $ask, 'lastPrice' => $bid]);
    }

    private function feed(): string
    {
        return file_get_contents(base_path('tests/Fixtures/swissquote/xau-aud.json'));
    }

    private function coin(string $name, string $market): Asset
    {
        return Asset::factory()->onExchange($this->exchange, $market)->create(['name' => $name]);
    }

    /**
     * @return array<string, string> the bid of each asset's latest price, by asset name
     */
    private function latest(): array
    {
        return Price::latestPerAsset()->with('asset')->get()
            ->mapWithKeys(fn (Price $price) => [$price->asset->name => (string) $price->bid])
            ->all();
    }

    public function test_it_stores_a_price_for_every_asset_with_a_price_source(): void
    {
        $bitcoin = $this->coin('Bitcoin', 'BTC-AUD');
        $this->coin('Cardano', 'ADA-AUD');
        Asset::factory()->for($this->user)->metal()->create(['name' => 'Gold']);
        Asset::factory()->for($this->user)->other()->create(['name' => 'Coins']);
        Http::fake([
            '*/markets/BTC-AUD/ticker' => Http::response($this->ticker('118440.39', '118603.28')),
            '*/markets/ADA-AUD/ticker' => Http::response($this->ticker('0.3657', '0.3677')),
            '*/XAU/AUD' => Http::response($this->feed()),
        ]);

        $this->artisan('nugget:fetch-prices')->expectsOutputToContain('Fetched 3 of 3 prices.')->assertSuccessful();

        $this->assertSame(['Bitcoin' => '118440.39', 'Cardano' => '0.3657', 'Gold' => '6002.774'], $this->latest());
        $this->assertSame(6, Price::count());

        $intraday = $bitcoin->prices()->where('resolution', PriceResolution::Intraday)->sole();
        $this->assertSame('2025-06-30 03:07:00', $intraday->at->toDateTimeString());
        $this->assertSame('118603.28', (string) $intraday->ask);
        $this->assertSame('2025-06-30', $bitcoin->prices()->where('resolution', PriceResolution::Day)->sole()->at->toDateString());
    }

    public function test_a_later_run_adds_an_intraday_row_and_moves_the_days_price(): void
    {
        $bitcoin = $this->coin('Bitcoin', 'BTC-AUD');
        Http::fake(['*/markets/BTC-AUD/ticker' => Http::sequence()
            ->push($this->ticker('100', '101'))
            ->push($this->ticker('110', '111'))]);

        $this->artisan('nugget:fetch-prices')->assertSuccessful();
        $this->app->forgetScopedInstances();
        $this->travelTo('2025-06-30 03:12:00');
        $this->artisan('nugget:fetch-prices')->assertSuccessful();

        $this->assertSame(['Bitcoin' => '110'], $this->latest());
        $this->assertSame(2, $bitcoin->prices()->where('resolution', PriceResolution::Intraday)->count());
        $this->assertSame(1, $bitcoin->prices()->where('resolution', PriceResolution::Day)->count());
    }

    public function test_one_failing_asset_does_not_stop_the_rest(): void
    {
        Log::spy();
        $this->coin('Bitcoin', 'BTC-AUD');
        $this->coin('Mistyped', 'NOPE-AUD');
        Asset::factory()->for($this->user)->metal()->create(['name' => 'Gold']);
        $this->coin('Cardano', 'ADA-AUD');
        Http::fake([
            '*/markets/BTC-AUD/ticker' => Http::response($this->ticker('100', '101')),
            '*/markets/NOPE-AUD/ticker' => Http::response('{"code":"InvalidMarketId","message":"invalid marketId"}', 400),
            '*/XAU/AUD' => Http::response('Bad gateway', 502),
            '*/markets/ADA-AUD/ticker' => Http::response($this->ticker('0.3657', '0.3677')),
        ]);

        $this->artisan('nugget:fetch-prices')
            ->expectsOutputToContain('Fetched 2 of 4 prices. 2 failed: see the log.')
            ->assertSuccessful();

        $this->assertSame(['Bitcoin' => '100', 'Cardano' => '0.3657'], $this->latest());
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_it_fails_when_no_price_could_be_fetched(): void
    {
        $this->coin('Bitcoin', 'BTC-AUD');
        Http::fake(['*' => Http::response('Service unavailable', 503)]);

        $this->artisan('nugget:fetch-prices')->expectsOutputToContain('Fetched 0 of 1 prices.')->assertFailed();

        $this->assertSame(0, Price::count());
    }

    public function test_with_no_assets_to_price_it_does_nothing_and_succeeds(): void
    {
        Asset::factory()->for($this->user)->other()->create();
        Http::fake();

        $this->artisan('nugget:fetch-prices')->expectsOutputToContain('Fetched 0 of 0 prices.')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_an_archived_asset_and_an_inactive_users_asset_are_left_out(): void
    {
        $this->coin('Bitcoin', 'BTC-AUD');
        Asset::factory()->onExchange($this->exchange, 'LTC-AUD')->archived()->create(['name' => 'Litecoin']);
        $gone = User::factory()->inactive()->create();
        Asset::factory()->onExchange(Exchange::factory()->for($gone)->create(), 'ETH-AUD')->create(['name' => 'Ether']);
        Http::fake(['*/markets/BTC-AUD/ticker' => Http::response($this->ticker('100', '101'))]);

        $this->artisan('nugget:fetch-prices')->expectsOutputToContain('Fetched 1 of 1 prices.')->assertSuccessful();

        $this->assertSame(['Bitcoin' => '100'], $this->latest());
        Http::assertSentCount(1);
    }

    public function test_two_assets_on_one_market_or_one_feed_symbol_cost_one_call(): void
    {
        $this->coin('Bitcoin', 'BTC-AUD');
        $this->coin('Bitcoin cold wallet', 'BTC-AUD');
        Asset::factory()->for($this->user)->metal()->create(['name' => 'Gold']);
        Asset::factory()->for($this->user)->metal()->create(['name' => 'Prospected gold', 'unit' => 'g']);
        // Another user's exchange is another account: its own call.
        Asset::factory()->onExchange(Exchange::factory()->create(), 'BTC-AUD')->create(['name' => 'Their Bitcoin']);
        Http::fake([
            '*/markets/BTC-AUD/ticker' => Http::response($this->ticker('100', '101')),
            '*/XAU/AUD' => Http::response($this->feed()),
        ]);

        $this->artisan('nugget:fetch-prices')->expectsOutputToContain('Fetched 5 of 5 prices.')->assertSuccessful();

        Http::assertSentCount(3);
        $this->assertSame(
            ['Bitcoin' => '100', 'Bitcoin cold wallet' => '100', 'Gold' => '6002.774', 'Prospected gold' => '192.99366558', 'Their Bitcoin' => '100'],
            $this->latest(),
        );
    }

    public function test_one_asset_can_be_fetched_by_its_id_even_when_archived(): void
    {
        $this->coin('Bitcoin', 'BTC-AUD');
        $litecoin = Asset::factory()->onExchange($this->exchange, 'LTC-AUD')->archived()->create(['name' => 'Litecoin']);
        Http::fake(['*/markets/LTC-AUD/ticker' => Http::response($this->ticker('90.9', '91'))]);

        $this->artisan('nugget:fetch-prices', ['--asset' => $litecoin->id])->expectsOutputToContain('Fetched 1 of 1 prices.')->assertSuccessful();

        $this->assertSame(['Litecoin' => '90.9'], $this->latest());
    }

    public function test_an_asset_id_that_does_not_exist_is_refused(): void
    {
        Http::fake();

        $this->artisan('nugget:fetch-prices', ['--asset' => 999])->expectsOutputToContain('There is no asset with the id 999.')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->sole(fn ($event) => str_contains($event->command, 'nugget:fetch-prices'));

        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }
}
