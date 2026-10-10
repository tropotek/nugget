<?php

namespace Tests\Feature;

use App\Enums\ChartRange;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard. The user is in Melbourne, and "today" is 1 August 2025.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-08-01 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);
    }

    /**
     * An asset holding these units at this cost each, selling at this price now.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function holding(string $name, string $units, string $cost, ?string $price, array $attributes = [], ?User $user = null): Asset
    {
        $asset = Asset::factory()->for($user ?? $this->user)->create(['name' => $name, ...$attributes]);
        Transaction::factory()->for($asset)->buy($units, $cost)->create(['occurred_at' => '2025-06-01 02:00:00']);
        app(ParcelMatcher::class)->rematch($asset);

        if ($price !== null) {
            Price::factory()->for($asset)->day('2025-08-01')->quoted($price, $price)->create();
        }

        return $asset;
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_it_shows_the_total_value_the_cost_base_and_the_unrealised_gain(): void
    {
        $this->holding('Bitcoin', '2', '100', '150');
        $this->holding('Gold', '1', '1000', '1100');

        $this->get('/')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Dashboard</h4>', false)
            // $300 and $1,100 against $200 and $1,000.
            ->assertSeeInOrder(['Total value', '$1,400.00', 'Cost base', '$1,200.00', 'Unrealised gain', 'text-success', '$200.00', '16.67%', 'AUD on exchanges', '$0.00'], false)
            ->assertDontSee('left out of these figures');
    }

    public function test_a_loss_is_shown_as_one(): void
    {
        $this->holding('Bitcoin', '2', '100', '90');

        $this->get('/')->assertSeeInOrder(['Total value', '$180.00', 'Unrealised gain', 'text-danger', '-$20.00', '-10.00%'], false);
    }

    public function test_the_totals_honour_in_total_and_leave_out_what_has_no_price(): void
    {
        $this->holding('Bitcoin', '2', '100', '150');
        $this->holding('Coin collection', '1', '5000', '9000', ['in_total' => false]);
        $this->holding('Stamps', '1', '850', null);
        $this->holding('Their bitcoin', '5', '100', '150', user: User::factory()->create());

        $this->get('/')
            ->assertSeeInOrder(['Total value', '$300.00', 'Cost base', '$200.00', 'Unrealised gain', '$100.00', '50.00%'])
            ->assertSee('1 holding has no price and is left out of these figures');
    }

    public function test_it_adds_up_the_aud_on_the_users_active_exchanges(): void
    {
        Exchange::factory()->for($this->user)->synced('1520.45')->create();
        Exchange::factory()->for($this->user)->synced('75.00')->create();
        Exchange::factory()->for($this->user)->synced('999.00')->inactive()->create();
        Exchange::factory()->synced('5000.00')->create();

        $this->get('/')->assertSeeInOrder(['AUD on exchanges', '$1,595.45']);
    }

    public function test_it_says_when_the_oldest_price_behind_the_totals_was_read(): void
    {
        $bitcoin = $this->holding('Bitcoin', '2', '100', '150');
        $gold = $this->holding('Gold', '1', '1000', '1100');
        $empty = Asset::factory()->for($this->user)->create();
        Price::factory()->for($empty)->day('2025-07-01')->create();
        Price::query()->where('asset_id', $empty->id)->update(['updated_at' => '2025-07-01 01:00:00']);
        Price::query()->where('asset_id', $bitcoin->id)->update(['updated_at' => '2025-08-01 01:55:00']);
        Price::query()->where('asset_id', $gold->id)->update(['updated_at' => '2025-08-01 01:50:00']);

        // Nothing is held of the asset priced a month ago, so its price does not count.
        $this->get('/')->assertSee('Prices as at')->assertSee('1 Aug 2025, 11:50am');
    }

    public function test_a_user_with_nothing_sees_zeros(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Total value', '$0.00', 'Cost base', '$0.00', 'Unrealised gain', '$0.00', 'AUD on exchanges', '$0.00'])
            ->assertDontSee('Prices as at');
    }

    public function test_the_totals_ask_htmx_to_refresh_them(): void
    {
        $this->get('/')->assertSee(
            '<div id="dashboard-totals" hx-get="'.route('dashboard').'" hx-trigger="every 60s" hx-target="#dashboard-totals" hx-swap="outerHTML">',
            false,
        );
    }

    public function test_htmx_is_sent_the_totals_alone_with_the_latest_figures(): void
    {
        $bitcoin = $this->holding('Bitcoin', '2', '100', '150');

        $this->get('/', ['HX-Request' => 'true', 'HX-Target' => 'dashboard-totals'])
            ->assertOk()
            ->assertSee('id="dashboard-totals"', false)
            ->assertSee('$300.00')
            ->assertDontSee('page-title', false)
            ->assertDontSee('<html', false);

        // The price moves, and the next refresh has it.
        $bitcoin->prices()->update(['bid' => '175.00000000']);

        $this->get('/', ['HX-Request' => 'true', 'HX-Target' => 'dashboard-totals'])->assertSee('$350.00')->assertDontSee('$300.00');
    }

    // The value chart

    public function test_the_chart_has_the_portfolios_value_on_each_day_of_the_last_month(): void
    {
        $bitcoin = $this->holding('Bitcoin', '2', '100', '150');
        Transaction::factory()->for($bitcoin)->buy('1', '100')->create(['occurred_at' => '2025-07-20 02:00:00']);
        Price::factory()->for($bitcoin)->day('2025-06-30')->quoted('90', '90')->create();
        Price::factory()->for($bitcoin)->day('2025-07-10')->quoted('100', '100')->create();
        Price::factory()->for($bitcoin)->day('2025-07-25')->quoted('120', '120')->create();
        $this->holding('Coin collection', '1', '5000', '9000', ['in_total' => false]);

        // 2 held on 10 July and 3 from the 20th: the units of the day, not today's.
        $points = [
            [Price::day('2025-07-10')->getTimestampMs(), '200.00'],
            [Price::day('2025-07-25')->getTimestampMs(), '360.00'],
            [Price::day('2025-08-01')->getTimestampMs(), '450.00'],
        ];

        $this->get('/')
            ->assertOk()
            ->assertViewHas('points', $points)
            ->assertViewHas('range', ChartRange::Month)
            ->assertSee('id="value-chart"', false)
            ->assertSee('data-points="'.e(json_encode($points)).'"', false)
            ->assertSee('data-times="false"', false)
            ->assertSee('href="'.e(route('dashboard', ['range' => '1y'])).'"', false)
            ->assertSeeInOrder(['Value', '48h', '1m', '1y', 'All']);
    }

    public function test_the_chart_reaches_back_as_far_as_its_range(): void
    {
        $bitcoin = $this->holding('Bitcoin', '2', '100', '150');
        Price::factory()->for($bitcoin)->day('2023-01-01')->create();
        Price::factory()->for($bitcoin)->day('2024-08-01')->create();

        $count = fn (int $expected) => fn (array $points) => count($points) === $expected;

        $this->get('/?range=1m')->assertViewHas('points', $count(1));
        $this->get('/?range=1y')->assertViewHas('points', $count(2));
        $this->get('/?range=all')->assertViewHas('points', $count(3));
        $this->get('/?range=nonsense')->assertOk()->assertViewHas('range', ChartRange::Month);
    }

    public function test_the_48_hour_chart_has_the_intraday_values_at_the_users_clock_time(): void
    {
        $bitcoin = $this->holding('Bitcoin', '2', '100', '150');
        Price::factory()->for($bitcoin)->at('2025-07-30 01:55:00')->quoted('100', '100')->create();
        Price::factory()->for($bitcoin)->at('2025-07-31 02:00:00')->quoted('110', '110')->create();
        Price::factory()->for($bitcoin)->at('2025-08-01 02:00:00')->quoted('120', '120')->create();

        // 02:00 UTC is midday in Melbourne.
        $midday = fn (string $date) => CarbonImmutable::parse("{$date} 12:00:00", 'UTC')->getTimestampMs();

        $this->get('/?range=48h')
            ->assertViewHas('points', [[$midday('2025-07-31'), '220.00'], [$midday('2025-08-01'), '240.00']])
            ->assertSee('data-times="true"', false);
    }

    public function test_a_chart_with_no_prices_says_so(): void
    {
        $this->get('/')->assertSee('id="value-chart"', false)->assertSee('No prices in this range.')->assertDontSee('data-chart', false);
    }

    public function test_htmx_is_sent_the_chart_alone(): void
    {
        $this->holding('Bitcoin', '2', '100', '150');

        $this->get('/?range=1y', ['HX-Request' => 'true', 'HX-Target' => 'value-chart'])
            ->assertOk()
            ->assertSee('id="value-chart"', false)
            ->assertSee('data-chart', false)
            ->assertDontSee('page-title', false)
            ->assertDontSee('dashboard-totals');
    }

    public function test_the_totals_refresh_does_not_carry_the_chart(): void
    {
        $this->holding('Bitcoin', '2', '100', '150');

        $this->get('/', ['HX-Request' => 'true', 'HX-Target' => 'dashboard-totals'])->assertDontSee('value-chart');
    }
}
