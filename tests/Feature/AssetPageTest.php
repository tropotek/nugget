<?php

namespace Tests\Feature;

use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Transaction;
use App\Models\User;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The page of one asset. The user is in Melbourne, and "today" is 1 August 2025.
 */
class AssetPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-08-01 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->asset = Asset::factory()->for($this->user)->create(['name' => 'Bitcoin', 'symbol' => 'BTC', 'unit' => 'BTC']);
        $this->actingAs($this->user);
    }

    private function add(string $day, TransactionFactory $transaction, ?Asset $asset = null): Transaction
    {
        return $transaction->for($asset ?? $this->asset)->create(['occurred_at' => "{$day} 02:00:00"]);
    }

    private function rematch(?Asset $asset = null): void
    {
        app(ParcelMatcher::class)->rematch($asset ?? $this->asset);
    }

    private function url(?Asset $asset = null): string
    {
        return '/assets/'.($asset ?? $this->asset)->id;
    }

    /**
     * 3 bought for $300 in January 2024 and 2 for $410 in June 2025, with 2 sold between.
     */
    private function history(): Transaction
    {
        $this->add('2024-01-10', Transaction::factory()->buy('3', '100'));
        $sale = $this->add('2024-03-10', Transaction::factory()->sell('2', '300', '5'));
        $this->add('2025-06-10', Transaction::factory()->buy('2', '200', '10')->estimated());
        $this->rematch();

        return $sale;
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get($this->url())->assertRedirect(route('login'));
    }

    public function test_another_users_asset_cannot_be_seen(): void
    {
        $this->get($this->url(Asset::factory()->create()))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->get($this->url())->assertForbidden();
    }

    public function test_the_asset_list_links_to_the_page(): void
    {
        $this->get('/assets')->assertSee('<a href="'.route('assets.show', $this->asset).'">Bitcoin</a>', false);
    }

    public function test_it_shows_the_units_the_cost_base_and_the_average_cost(): void
    {
        $this->history();

        $this->get($this->url())
            ->assertOk()
            ->assertSee('<h4 class="page-title">Bitcoin</h4>', false)
            ->assertSeeInOrder(['Units held', '3', 'BTC', 'Cost base', '$510.00', 'Estimated', 'Average cost', '$170.00', 'per BTC', 'Open parcels', '2'])
            ->assertSee('href="'.route('transactions.create', ['asset' => $this->asset->id]).'"', false)
            ->assertSee('href="'.route('transactions.index', ['asset' => $this->asset->id]).'"', false)
            ->assertSee('href="'.route('assets.edit', $this->asset).'"', false);
    }

    public function test_an_asset_with_nothing_held_says_so(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSeeInOrder(['Units held', '0', 'Cost base', '$0.00', 'Average cost', 'None held', 'Open parcels', '0'])
            ->assertSee('No open parcels: nothing is held.')
            ->assertSee('No transactions yet.')
            ->assertDontSee('Estimated');
    }

    public function test_a_cheap_coins_average_cost_keeps_its_places(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('3000', '0.00012'));
        $this->rematch();

        $this->get($this->url())->assertSeeInOrder(['Average cost', '$0.00012', 'per BTC']);
    }

    public function test_it_shows_the_details_of_the_asset(): void
    {
        $exchange = Exchange::factory()->for($this->user)->create(['label' => 'BTC Markets']);
        $asset = Asset::factory()->onExchange($exchange, 'BTC/AUD')->archived()
            ->create(['name' => 'Old coin', 'symbol' => 'OLD', 'in_total' => false, 'notes' => "Line one\n<b>Line two</b>"]);

        $this->get($this->url($asset))
            ->assertSeeInOrder(['Symbol', 'OLD', 'Class', 'Crypto', 'Priced by', 'BTC Markets', 'BTC/AUD', 'In the total', 'No', 'Archived', 'Notes'])
            ->assertSee("Line one<br />\n&lt;b&gt;Line two&lt;/b&gt;", false);

        $this->get($this->url())->assertSeeInOrder(['Priced by', 'No price', 'In the total', 'Yes'])->assertDontSee('Archived');
    }

    public function test_it_lists_the_open_parcels_oldest_first(): void
    {
        $this->history();
        $first = $this->asset->transactions()->oldest('occurred_at')->first();

        $this->get($this->url())
            ->assertSeeInOrder([
                'id="open-parcels"',
                // Acquired, units, units left, cost base left, cost per unit.
                '10 Jan 2024', '3', '1', '$100.00', '$100.00', '12 months',
                '10 Jun 2025', '2', '2', '$410.00', '$205.00', 'Estimated',
                'id="asset-history"',
            ], false)
            ->assertSee('href="'.route('transactions.edit', $first).'" class="btn btn-sm btn-light">Source', false);
    }

    public function test_a_parcel_is_marked_long_held_from_the_day_after_a_year(): void
    {
        $this->add('2024-08-01', Transaction::factory()->buy('1', '100'));
        $this->rematch();

        $this->get($this->url())->assertDontSee('12 months');

        $this->travelTo('2025-08-02 02:00:00');
        $this->get($this->url())->assertSee('12 months');
    }

    public function test_a_parcel_that_is_used_up_is_not_listed(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '111'));
        $this->add('2024-02-10', Transaction::factory()->buy('1', '222'));
        $this->add('2024-03-10', Transaction::factory()->sell('1', '300'));
        $this->rematch();

        $this->get($this->url())
            ->assertSeeInOrder(['id="open-parcels"', '10 Feb 2024', '$222.00', 'id="asset-history"'], false)
            ->assertViewHas('parcels', fn ($parcels) => $parcels->count() === 1);
    }

    public function test_it_lists_the_transactions_most_recent_first_with_the_cost_at_the_time(): void
    {
        $sale = $this->history();
        $this->add('2025-07-01', Transaction::factory()->transferOut('0.5'));
        $this->rematch();

        $this->get($this->url())
            ->assertSeeInOrder([
                'id="asset-history"',
                '1 Jul 2025', 'Transfer out', '-0.5 BTC',
                '10 Jun 2025', 'Buy', 'Estimated', '2 BTC', '$200.00', '$10.00', '$410.00',
                '10 Mar 2024', 'Sell', '-2 BTC', '$300.00', '$5.00', '$595.00',
                '10 Jan 2024', 'Buy', '3 BTC', '$100.00', '$0.00', '$300.00',
            ], false)
            ->assertSee('<tr class="table-warning">', false)
            ->assertSee('href="'.route('transactions.parcels.edit', $sale).'"', false)
            ->assertSee('href="'.route('transactions.edit', $sale).'"', false)
            ->assertDontSee('All 4 transactions');
    }

    public function test_only_this_assets_transactions_and_parcels_are_shown(): void
    {
        $this->history();
        $gold = Asset::factory()->for($this->user)->metal()->create();
        $this->add('2024-05-05', Transaction::factory()->buy('7', '4321'), $gold);
        $this->rematch($gold);

        $this->get($this->url())->assertDontSee('5 May 2024')->assertDontSee('4,321');
        $this->get($this->url($gold))->assertSee('5 May 2024')->assertDontSee('10 Jan 2024');
    }

    public function test_a_long_history_shows_the_latest_and_links_to_the_rest(): void
    {
        foreach (range(1, 28) as $day) {
            $this->add(sprintf('2024-01-%02d', $day), Transaction::factory()->buy('1', (string) (1000 + $day)));
        }
        $this->rematch();

        $this->get($this->url())
            ->assertViewHas('transactions', fn ($transactions) => $transactions->count() === 25
                && $transactions->first()->occurred_at->toDateString() === '2024-01-28'
                && $transactions->last()->occurred_at->toDateString() === '2024-01-04')
            ->assertSee('All 28 transactions');
    }
}
