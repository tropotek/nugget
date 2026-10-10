<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Exchange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradingViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get('/tradingview')->assertRedirect(route('login'));
    }

    public function test_the_sidebar_shows_the_page(): void
    {
        $this->get('/assets')->assertSee('href="'.route('tradingview').'"', false);
    }

    public function test_it_shows_the_first_symbol_on_the_watchlist_to_begin_with(): void
    {
        $this->get('/tradingview')
            ->assertOk()
            ->assertSee('<h4 class="page-title">TradingView</h4>', false)
            ->assertSee('data-tradingview data-symbol="BTCAUD"', false)
            ->assertSee('data-watchlist="'.e(json_encode(['BTCAUD', 'ADAAUD', 'ETHAUD', 'AUDUSD'])).'"', false)
            ->assertSee('name="symbol" value="BTCAUD"', false)
            ->assertSee('https://www.tradingview.com/symbols/BTCAUD/', false);
    }

    public function test_it_shows_the_symbol_chosen(): void
    {
        $this->get('/tradingview?symbol=ethaud')
            ->assertSee('data-symbol="ETHAUD"', false)
            ->assertSee('href="'.e(route('tradingview', ['symbol' => 'ETHAUD'])).'" class="btn btn-sm btn-primary"', false)
            ->assertSee('href="'.e(route('tradingview', ['symbol' => 'BTCAUD'])).'" class="btn btn-sm btn-light"', false);

        $this->get('/tradingview?symbol=BINANCE:BTCUSDT')->assertSee('data-symbol="BINANCE:BTCUSDT"', false);
    }

    public function test_something_that_is_not_a_symbol_is_passed_over(): void
    {
        foreach (['symbol=<script>alert(1)</script>', 'symbol=', 'symbol[]=BTCAUD', 'symbol='.str_repeat('A', 33), 'symbol=BTC%20AUD'] as $query) {
            $this->get("/tradingview?{$query}")->assertOk()->assertSee('data-symbol="BTCAUD"', false)->assertDontSee('<script>alert', false);
        }
    }

    public function test_the_watchlist_starts_with_the_markets_of_the_users_own_assets(): void
    {
        $exchange = Exchange::factory()->for($this->user)->create();
        Asset::factory()->onExchange($exchange, 'XRP-AUD')->create(['name' => 'Ripple']);
        Asset::factory()->onExchange($exchange, 'BTC-AUD')->create(['name' => 'Bitcoin']);
        Asset::factory()->onExchange($exchange, 'BTC-AUD')->create(['name' => 'Bitcoin cold wallet']);
        Asset::factory()->onExchange($exchange, 'DOGE-AUD')->archived()->create();
        Asset::factory()->for($this->user)->create(['feed_symbol' => 'XAU/AUD']);
        Asset::factory()->onExchange(Exchange::factory()->create(), 'LTC-AUD')->create();

        $this->get('/tradingview')
            ->assertViewHas('watchlist', ['BTCAUD', 'XRPAUD', 'ADAAUD', 'ETHAUD', 'AUDUSD'])
            ->assertSee('data-symbol="BTCAUD"', false);
    }

    public function test_the_chart_is_in_the_users_timezone_as_tradingview_names_it(): void
    {
        $this->get('/tradingview')->assertSee('data-timezone="Australia/Sydney"', false);

        $this->user->update(['timezone' => 'Australia/Perth']);

        $this->get('/tradingview')->assertSee('data-timezone="Australia/Perth"', false);
    }
}
