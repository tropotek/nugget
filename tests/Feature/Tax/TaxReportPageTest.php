<?php

namespace Tests\Feature\Tax;

use App\Enums\AssetClass;
use App\Facades\Settings;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\TaxYear;
use App\Models\Transaction;
use App\Models\User;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The tax report page. The user is in Melbourne, "today" is 1 August 2025
 * (2025–26), and the first year reported on is 2024–25.
 */
class TaxReportPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Asset $bitcoin;

    private Asset $gold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-08-01 02:00:00');
        Settings::set('first_report_year', 2025);

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->bitcoin = Asset::factory()->for($this->user)->create(['name' => 'Bitcoin', 'class' => AssetClass::Crypto, 'unit' => 'BTC']);
        $this->gold = Asset::factory()->for($this->user)->create(['name' => 'Gold', 'class' => AssetClass::Metal, 'unit' => 'oz']);
        $this->actingAs($this->user);
    }

    private function add(Asset $asset, string $day, TransactionFactory $transaction): Transaction
    {
        $added = $transaction->for($asset)->create(['occurred_at' => "{$day} 02:00:00"]);
        app(ParcelMatcher::class)->rematch($asset);

        return $added;
    }

    /**
     * 2024–25: in crypto a long-held gain of $3,000, a short-held gain of
     * $1,500 and a loss of $2,000; in gold a long-held gain of $900.
     * 2023–24, which is closed, has one sale.
     */
    private function ledger(): void
    {
        $this->add($this->bitcoin, '2023-01-10', Transaction::factory()->buy('2', '1000'));
        $this->add($this->bitcoin, '2024-03-04', Transaction::factory()->sell('1', '1200'));
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->buy('1', '5000'));
        $this->add($this->bitcoin, '2024-09-01', Transaction::factory()->buy('1', '9000'));
        $this->add($this->bitcoin, '2024-10-01', Transaction::factory()->sell('1', '4000'));
        $this->add($this->bitcoin, '2024-11-01', Transaction::factory()->sell('1', '6500'));
        $this->add($this->bitcoin, '2025-02-01', Transaction::factory()->sell('1', '7000'));
        $this->add($this->gold, '2022-05-05', Transaction::factory()->buy('2', '2500'));
        $this->add($this->gold, '2025-03-03', Transaction::factory()->sell('1', '3400'));
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        auth()->logout();

        $this->get('/tax-report')->assertRedirect(route('login'));
        $this->get('/tax-report/2025')->assertRedirect(route('login'));
        $this->put('/tax-report/2025')->assertRedirect(route('login'));
    }

    public function test_the_sidebar_shows_the_tax_report(): void
    {
        $this->get('/assets')->assertSee('href="'.route('tax-report.index').'"', false);
    }

    // Choosing a year

    public function test_it_opens_on_the_latest_open_year_with_disposals(): void
    {
        $this->ledger();
        $this->add($this->gold, '2025-07-10', Transaction::factory()->sell('1', '3500'));

        $this->get('/tax-report')->assertRedirect(route('tax-report.show', 2026));
    }

    public function test_with_nothing_sold_in_an_open_year_it_says_so(): void
    {
        $this->add($this->bitcoin, '2023-01-10', Transaction::factory()->buy('2', '1000'));
        $this->add($this->bitcoin, '2024-03-04', Transaction::factory()->sell('1', '1200'));

        $this->get('/tax-report')
            ->assertOk()
            ->assertSee('Nothing to report yet: nothing has been sold in 2024–25 or since.')
            ->assertSee('2023–24 has sales, but is closed');
    }

    public function test_the_year_picker_offers_the_open_years_only(): void
    {
        $this->ledger();
        $this->add($this->gold, '2025-07-10', Transaction::factory()->sell('1', '3500'));

        $this->get('/tax-report/2025')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Tax report 2024–25</h4>', false)
            ->assertSee('href="'.route('tax-report.show', 2026).'" class="btn btn-light">2025–26', false)
            ->assertSee('href="'.route('tax-report.show', 2025).'" class="btn btn-primary">2024–25', false)
            ->assertDontSee(route('tax-report.show', 2024))
            ->assertSee('2023–24 is closed and has no report.');
    }

    public function test_a_closed_year_has_no_report(): void
    {
        $this->ledger();

        $this->get('/tax-report/2024')
            ->assertRedirect(route('tax-report.index'))
            ->assertSessionHas('warning', '2023–24 is closed: it was lodged outside Nugget, so there is no report for it.');

        // The message is still there after the start page has sent the reader on to an open year.
        $this->followingRedirects()->get('/tax-report/2024')
            ->assertSee('<h4 class="page-title">Tax report 2024–25</h4>', false)
            ->assertSee('2023–24 is closed: it was lodged outside Nugget');

        $this->put('/tax-report/2024', ['losses_carried_forward' => '100'])->assertRedirect(route('tax-report.index'));
        $this->assertSame(0, TaxYear::count());
    }

    public function test_a_year_that_could_not_be_one_is_not_found(): void
    {
        $this->get('/tax-report/1999')->assertNotFound();
        $this->get('/tax-report/2028')->assertNotFound();
        $this->get('/tax-report/abc')->assertNotFound();
        $this->get('/tax-report/2027')->assertOk();
    }

    // The report

    public function test_the_summary_shows_the_working_from_the_calculator(): void
    {
        $this->ledger();

        $this->get('/tax-report/2025')
            ->assertOk()
            ->assertSeeInOrder([
                'id="tax-summary"', 'Crypto', 'Other assets', 'Total',
                'Gains on assets held 12 months or less', '$1,500.00', '$0.00', '$1,500.00',
                'Gains on assets held longer than 12 months', '$3,000.00', '$900.00', '$3,900.00',
                'Losses', '$2,000.00', '$0.00', '$2,000.00',
                'Total capital gains', '$5,400.00',
                'Losses this year', '$2,000.00',
                'Losses carried forward from earlier years', '$0.00',
                'Losses applied', '$2,000.00',
                '50% discount', '$3,400.00', '$1,700.00',
                'Net capital gain', '$1,700.00',
                'Losses to carry forward to later years', '$0.00',
            ], false);
    }

    public function test_each_section_lists_its_disposals_in_the_order_sold(): void
    {
        $this->ledger();
        $sale = $this->bitcoin->transactions()->where('occurred_at', '2024-10-01 02:00:00')->sole();

        $this->get('/tax-report/2025')
            ->assertSeeInOrder([
                'id="disposals-crypto"',
                // Asset, acquired, sold, units, cost base, proceeds, gain or loss.
                'Bitcoin', '10 Jan 2023', '1 Oct 2024', '1 BTC', '$1,000.00', '$4,000.00', '$3,000.00', 'Discount',
                'Bitcoin', '1 Aug 2024', '1 Nov 2024', '1 BTC', '$5,000.00', '$6,500.00', '$1,500.00',
                'Bitcoin', '1 Sep 2024', '1 Feb 2025', '1 BTC', '$9,000.00', '$7,000.00', '-$2,000.00',
                '3 disposals', '$15,000.00', '$17,500.00', '$2,500.00',
                'id="disposals-other"',
                'Gold', '5 May 2022', '3 Mar 2025', '1 oz', '$2,500.00', '$3,400.00', '$900.00', 'Discount',
                '1 disposal', '$2,500.00', '$3,400.00', '$900.00',
            ], false)
            ->assertSee('href="'.route('transactions.parcels.edit', $sale).'"', false)
            ->assertSee('href="'.route('assets.show', $this->bitcoin).'"', false)
            // The sale of March 2024 is in a closed year.
            ->assertDontSee('4 Mar 2024');
    }

    public function test_a_long_held_loss_is_not_marked_for_the_discount(): void
    {
        $this->add($this->bitcoin, '2023-01-10', Transaction::factory()->buy('1', '5000'));
        $this->add($this->bitcoin, '2024-10-01', Transaction::factory()->sell('1', '4000'));

        $this->get('/tax-report/2025')->assertSee('-$1,000.00')->assertDontSee('Discount');
    }

    public function test_a_section_with_nothing_sold_says_so(): void
    {
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->buy('1', '5000'));
        $this->add($this->bitcoin, '2024-11-01', Transaction::factory()->sell('1', '6500'));

        $this->get('/tax-report/2025')->assertSeeInOrder(['id="disposals-other"', 'Nothing was sold in this section in 2024–25.'], false);
    }

    public function test_disposals_that_used_an_estimated_parcel_are_marked_and_counted_at_the_top(): void
    {
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->buy('1', '5000')->estimated());
        $this->add($this->bitcoin, '2024-08-02', Transaction::factory()->buy('1', '5000'));
        $this->add($this->bitcoin, '2024-11-01', Transaction::factory()->sell('2', '6000'));
        $this->add($this->gold, '2024-09-21', Transaction::factory()->buy('1', '100')->estimated());
        $this->add($this->gold, '2025-01-15', Transaction::factory()->sell('1', '150'));

        $this->get('/tax-report/2025')
            ->assertSeeInOrder([
                'id="estimated-warning"', '2 disposals in this report used a parcel with an estimated cost.',
                'id="tax-summary"',
                'id="disposals-crypto"', '<tr class="table-warning">', 'Estimated',
                'id="disposals-other"', '<tr class="table-warning">', 'Estimated',
            ], false);
    }

    public function test_a_report_with_no_estimates_has_no_warning(): void
    {
        $this->ledger();

        $this->get('/tax-report/2025')->assertDontSee('estimated-warning')->assertDontSee('Estimated');
    }

    public function test_only_the_users_own_disposals_are_in_the_report(): void
    {
        $this->ledger();
        $theirs = Asset::factory()->create(['name' => 'Their coin']);
        $this->add($theirs, '2024-08-01', Transaction::factory()->buy('1', '100'));
        $this->add($theirs, '2024-11-01', Transaction::factory()->sell('1', '99999'));

        $this->get('/tax-report/2025')->assertDontSee('Their coin')->assertSeeInOrder(['Net capital gain', '$1,700.00']);
    }

    // Losses carried forward

    public function test_losses_carried_forward_are_saved_for_the_year_and_change_the_net_gain(): void
    {
        $this->ledger();

        $this->put('/tax-report/2025', ['losses_carried_forward' => '1000.50'])
            ->assertRedirect(route('tax-report.show', 2025))
            ->assertSessionHas('success', 'Saved the losses carried forward into 2024–25.');

        $this->assertSame('1000.50', $this->user->taxYears()->where('year', 2025)->sole()->losses_carried_forward->toFixed(2));

        // $3,000.50 of losses in all: $2,399.50 of long-held gains are left to halve.
        $this->get('/tax-report/2025')
            ->assertSee('name="losses_carried_forward" required', false)
            ->assertSee('value="1000.50"', false)
            ->assertSeeInOrder(['Losses carried forward from earlier years', '$1,000.50', 'Losses applied', '$3,000.50', 'Net capital gain', '$1,199.75']);
    }

    public function test_saving_again_replaces_the_figure_and_each_year_has_its_own(): void
    {
        $this->ledger();

        $this->put('/tax-report/2025', ['losses_carried_forward' => '1000']);
        $this->put('/tax-report/2025', ['losses_carried_forward' => '0']);
        $this->put('/tax-report/2026', ['losses_carried_forward' => '75']);

        $this->assertSame(['2025' => '0.00', '2026' => '75.00'], $this->user->taxYears()->orderBy('year')->get()
            ->mapWithKeys(fn (TaxYear $year) => [(string) $year->year => $year->losses_carried_forward->toFixed(2)])->all());
        $this->get('/tax-report/2025')->assertSeeInOrder(['Net capital gain', '$1,700.00']);
    }

    public function test_the_losses_have_to_be_a_plain_amount_of_zero_or_more(): void
    {
        foreach (['', '-5', 'lots', '1.234', '1,000'] as $bad) {
            $this->from('/tax-report/2025')->put('/tax-report/2025', ['losses_carried_forward' => $bad])
                ->assertRedirect('/tax-report/2025')
                ->assertSessionHasErrors('losses_carried_forward');
        }

        $this->assertSame(0, TaxYear::count());
    }

    public function test_one_users_losses_are_not_anothers(): void
    {
        $this->ledger();
        $other = User::factory()->create();
        $other->taxYears()->create(['year' => 2025, 'losses_carried_forward' => '5000']);

        $this->get('/tax-report/2025')->assertSeeInOrder(['Losses carried forward from earlier years', '$0.00', 'Net capital gain', '$1,700.00']);

        $this->put('/tax-report/2025', ['losses_carried_forward' => '10']);

        $this->assertSame('5000.00', $other->taxYears()->sole()->losses_carried_forward->toFixed(2));
    }

    public function test_the_field_says_what_the_year_before_leaves_to_carry_forward(): void
    {
        // 2024–25 is a net loss of $1,700; 2025–26 has a gain.
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->buy('3', '5000'));
        $this->add($this->bitcoin, '2024-11-01', Transaction::factory()->sell('1', '3300'));
        $this->add($this->bitcoin, '2025-07-10', Transaction::factory()->sell('1', '6000'));

        $this->get('/tax-report/2026')->assertSee('The 2024–25 report leaves $1,700.00 to carry forward.');
        // Nothing is filled in for the user: the figure is theirs to enter.
        $this->get('/tax-report/2026')->assertSee('value="0.00"', false)->assertSeeInOrder(['Net capital gain', '$1,000.00']);

        $this->get('/tax-report/2025')->assertSee('2023–24 was lodged outside Nugget: take the figure from that return.');
    }

    public function test_the_page_says_it_is_a_working_paper(): void
    {
        $this->ledger();

        $this->get('/tax-report/2025')->assertSee('A working paper for you and your accountant, not tax advice.');
    }
}
