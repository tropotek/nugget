<?php

namespace Tests\Feature;

use App\Enums\TransactionSource;
use App\Models\Asset;
use App\Models\Transaction;
use App\Models\User;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The list of transactions. The user is in Melbourne. Each transaction here
 * has its own number of units, which is how a test names the rows it expects.
 */
class TransactionListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Asset $bitcoin;

    private Asset $gold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->bitcoin = Asset::factory()->for($this->user)->create(['name' => 'Bitcoin', 'symbol' => 'BTC', 'unit' => 'BTC']);
        $this->gold = Asset::factory()->for($this->user)->metal()->create(['name' => 'Gold bullion']);
        $this->actingAs($this->user);
    }

    /**
     * Add a transaction at 02:00 UTC on a day (1pm in Melbourne in summer), or at a full UTC time.
     */
    private function add(string $when, TransactionFactory $transaction, ?Asset $asset = null): Transaction
    {
        return $transaction->for($asset ?? $this->bitcoin)->create([
            'occurred_at' => strlen($when) === 10 ? "{$when} 02:00:00" : $when,
        ]);
    }

    /**
     * A buy, a sale and a reward of Bitcoin and a buy of gold, of 1, 2, 3 and 4 units.
     */
    private function history(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100')->fromExchange('t-1'));
        $this->add('2024-02-10', Transaction::factory()->sell('2', '9')->estimated()->state(['source' => TransactionSource::Import]));
        $this->add('2024-03-10', Transaction::factory()->reward('3', '50'));
        $this->add('2024-04-10', Transaction::factory()->buy('4', '3000', '25'), $this->gold);
    }

    /**
     * The units of the rows on the page, in order.
     *
     * @return list<string>
     */
    private function listed(TestResponse $response): array
    {
        /** @var LengthAwarePaginator<int, Transaction> $transactions */
        $transactions = $response->assertOk()->viewData('transactions');

        return collect($transactions->items())->map(fn (Transaction $t) => (string) $t->units)->all();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get('/transactions')->assertRedirect(route('login'));
    }

    public function test_the_sidebar_shows_transactions(): void
    {
        $this->get('/')->assertSee('href="'.route('transactions.index').'"', false);
    }

    public function test_it_lists_the_users_transactions_newest_first(): void
    {
        $this->history();

        $response = $this->get('/transactions');

        $this->assertSame(['4', '3', '2', '1'], $this->listed($response));
        $response
            ->assertSee('<h4 class="page-title">Transactions</h4>', false)
            ->assertSeeInOrder(['10 Apr 2024, 12:00pm', 'Gold bullion', 'Buy', '4 oz', '$3,000.00', '$25.00', '$12,025.00', 'Manual'])
            ->assertSeeInOrder(['Reward', '3 BTC', '$150.00', 'Sell', '-2 BTC', 'Import', 'Buy', '1 BTC', 'Exchange'])
            ->assertSee('href="'.route('transactions.create').'"', false);
    }

    public function test_each_row_links_to_its_edit_form_with_a_full_page_load(): void
    {
        $buy = $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));

        $this->get('/transactions')->assertSee('href="'.route('transactions.edit', $buy).'" hx-boost="false"', false);
    }

    public function test_other_users_transactions_are_left_out(): void
    {
        $this->history();
        $this->add('2024-05-10', Transaction::factory()->buy('99', '1'), Asset::factory()->create(['name' => 'Somebody elses']));

        $response = $this->get('/transactions');

        $this->assertSame(['4', '3', '2', '1'], $this->listed($response));
        $response->assertDontSee('Somebody elses');
    }

    public function test_an_estimated_row_is_marked(): void
    {
        $this->history();

        $this->get('/transactions?type=sell')
            ->assertSee('<tr class="table-warning">', false)
            ->assertSee('Estimated</span>', false);

        $this->get('/transactions?type=buy')
            ->assertDontSee('<tr class="table-warning">', false)
            ->assertDontSee('Estimated</span>', false);
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->get('/transactions')->assertSee('No transactions yet.');
        $this->get('/transactions?type=sell')->assertSee('No transactions match.');
    }

    // Filters

    public function test_it_filters_by_asset(): void
    {
        $this->history();

        $response = $this->get("/transactions?asset={$this->gold->id}");

        $this->assertSame(['4'], $this->listed($response));
        $response
            ->assertSee('<option value="'.$this->gold->id.'" selected>', false)
            ->assertSee('href="'.route('transactions.create', ['asset' => $this->gold->id]).'"', false);
    }

    public function test_it_filters_by_type_and_by_source(): void
    {
        $this->history();

        $this->assertSame(['4', '1'], $this->listed($this->get('/transactions?type=buy')));
        $this->assertSame(['1'], $this->listed($this->get('/transactions?source=exchange')));
        $this->assertSame(['2'], $this->listed($this->get('/transactions?source=import')));
        $this->assertSame(['1'], $this->listed($this->get("/transactions?type=buy&asset={$this->bitcoin->id}")));
    }

    public function test_it_filters_by_the_estimated_flag(): void
    {
        $this->history();

        $this->assertSame(['2'], $this->listed($this->get('/transactions?estimated=1')));
        $this->assertSame(['4', '3', '1'], $this->listed($this->get('/transactions?estimated=0')));
    }

    public function test_it_filters_by_a_range_of_days_including_both_ends(): void
    {
        $this->history();

        $this->assertSame(['3', '2'], $this->listed($this->get('/transactions?from=2024-02-10&to=2024-03-10')));
        $this->assertSame(['4', '3'], $this->listed($this->get('/transactions?from=2024-03-10')));
        $this->assertSame(['1'], $this->listed($this->get('/transactions?to=2024-01-10')));
    }

    public function test_the_days_are_the_users_days_not_utc_days(): void
    {
        // 30 June at 11pm and 1 July at 9am in Melbourne are both 30 June in UTC.
        $this->add('2025-06-30 13:00:00', Transaction::factory()->buy('1', '100'));
        $this->add('2025-06-30 23:00:00', Transaction::factory()->buy('2', '100'));

        $this->assertSame(['1'], $this->listed($this->get('/transactions?to=2025-06-30')));
        $this->assertSame(['2'], $this->listed($this->get('/transactions?from=2025-07-01')));
    }

    public function test_filters_it_does_not_understand_are_passed_over(): void
    {
        $this->history();
        $theirs = Asset::factory()->create();

        $response = $this->get("/transactions?asset={$theirs->id}&type=gift&source=post&from=yesterday&to=2024-13-45&estimated=maybe&sort=fee&dir=up");

        $this->assertSame(['4', '3', '2', '1'], $this->listed($response));
        $this->assertSame(['4', '3', '2', '1'], $this->listed($this->get('/transactions?type[]=buy&asset[]=1')));
    }

    // Sorting

    public function test_it_sorts_by_date_either_way(): void
    {
        $this->history();

        $this->assertSame(['1', '2', '3', '4'], $this->listed($this->get('/transactions?sort=date&dir=asc')));
        $this->assertSame(['4', '3', '2', '1'], $this->listed($this->get('/transactions?sort=date&dir=desc')));
    }

    public function test_it_sorts_units_and_totals_as_numbers(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('9', '1'));
        $this->add('2024-01-11', Transaction::factory()->buy('10', '50'));
        $this->add('2024-01-12', Transaction::factory()->buy('100', '2'));
        $this->add('2024-01-13', Transaction::factory()->adjust('-5'));

        $this->assertSame(['-5', '9', '10', '100'], $this->listed($this->get('/transactions?sort=units')));
        $this->assertSame(['100', '10', '9', '-5'], $this->listed($this->get('/transactions?sort=units&dir=desc')));
        // Totals of 0, 9, 200 and 500.
        $this->assertSame(['-5', '9', '100', '10'], $this->listed($this->get('/transactions?sort=total')));
    }

    public function test_it_sorts_by_asset_and_by_type_with_the_newest_first_within_each(): void
    {
        $this->history();

        $this->assertSame(['1', '2', '3', '4'], $this->listed($this->get('/transactions?sort=asset')));
        $this->assertSame(['4', '3', '2', '1'], $this->listed($this->get('/transactions?sort=asset&dir=desc')));
        $this->assertSame(['1', '4', '3', '2'], $this->listed($this->get('/transactions?sort=type')));
    }

    public function test_a_heading_sorts_by_its_column_and_turns_the_order_round_when_it_is_the_order(): void
    {
        $this->history();

        $this->get('/transactions?type=buy')
            ->assertSee('href="'.e(route('transactions.index', ['type' => 'buy', 'sort' => 'date', 'dir' => 'asc'])).'"', false)
            ->assertSee('href="'.e(route('transactions.index', ['type' => 'buy', 'sort' => 'units', 'dir' => 'asc'])).'"', false)
            ->assertSee('ri-arrow-down-s-line');

        $this->get('/transactions?type=buy&sort=units')
            ->assertSee('href="'.e(route('transactions.index', ['type' => 'buy', 'sort' => 'units', 'dir' => 'desc'])).'"', false)
            ->assertSee('href="'.e(route('transactions.index', ['type' => 'buy', 'sort' => 'date', 'dir' => 'desc'])).'"', false)
            ->assertSee('ri-arrow-up-s-line');
    }

    // Paging

    public function test_the_list_is_paged_and_the_links_keep_the_search(): void
    {
        foreach (range(1, 30) as $day) {
            $this->add(sprintf('2024-01-%02d', $day), Transaction::factory()->buy((string) $day, '100'));
        }
        $this->add('2024-02-01', Transaction::factory()->sell('5', '100'));

        $response = $this->get('/transactions?type=buy&sort=units&junk=1');

        $this->assertSame(array_map('strval', range(1, 25)), $this->listed($response));
        $response
            ->assertViewHas('transactions', fn (LengthAwarePaginator $page) => $page->total() === 30)
            ->assertSee(e(route('transactions.index', ['type' => 'buy', 'sort' => 'units', 'dir' => 'asc', 'page' => 2])), false)
            ->assertDontSee('junk');

        $this->assertSame(['26', '27', '28', '29', '30'], $this->listed($this->get('/transactions?type=buy&sort=units&page=2')));
    }

    // htmx

    public function test_the_page_has_the_filters_around_the_table(): void
    {
        $this->get('/transactions')
            ->assertSee('<html', false)
            ->assertSee('hx-get="'.route('transactions.index').'"', false)
            ->assertSee('hx-trigger="change"', false)
            ->assertSee('id="transaction-table" hx-boost="true" hx-target="#transaction-table"', false);
    }

    public function test_htmx_gets_the_table_alone(): void
    {
        $this->history();

        $response = $this->get('/transactions?type=buy', ['HX-Request' => 'true', 'HX-Target' => 'transaction-table']);

        $this->assertSame(['4', '1'], $this->listed($response));
        $response
            ->assertSee('id="transaction-table"', false)
            ->assertSee('Gold bullion')
            ->assertDontSee('<html', false)
            ->assertDontSee('page-title')
            ->assertDontSee('name="asset"', false);
    }

    public function test_a_search_keeps_its_order_when_the_filters_change(): void
    {
        $this->get('/transactions?sort=units&dir=desc')
            ->assertSee('<input type="hidden" name="sort" value="units">', false)
            ->assertSee('<input type="hidden" name="dir" value="desc">', false);

        $this->get('/transactions')->assertDontSee('name="sort"', false);
    }
}
