<?php

namespace Tests\Feature\Tax;

use App\Enums\AssetClass;
use App\Enums\TaxSection;
use App\Facades\Settings;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\TaxYear;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FinancialYear;
use App\Tax\CgtCalculator;
use App\Tax\TaxReportCsv;
use App\Tax\TaxReportPdf;
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
        // Every class, where the default is crypto only. The default has its own tests below.
        config(['nugget.tax.default_asset_classes' => ['crypto', 'metal', 'other']]);

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
            ->assertSessionHas('success', 'Saved the choices for 2024–25.');

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

    // The PDF

    public function test_the_report_downloads_as_a_pdf_with_a_page_for_the_summary_and_each_section(): void
    {
        $this->ledger();

        $this->get('/tax-report/2025')->assertSee('href="'.route('tax-report.pdf', 2025).'"', false);

        $response = $this->get('/tax-report/2025/pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="nugget-capital-gains-2024-25.pdf"');

        $pdf = $response->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(3, preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf));
    }

    public function test_the_pdf_prints_the_same_report_with_who_when_and_what_it_is(): void
    {
        $this->ledger();
        $this->user->update(['name' => 'Pat Example', 'email' => 'pat@example.com']);
        $this->user->taxYears()->create(['year' => 2025, 'losses_carried_forward' => '1000.50']);
        $report = app(CgtCalculator::class)->forYear($this->user, new FinancialYear(2025));

        $html = app(TaxReportPdf::class)->html($this->user, $report);

        foreach ([
            'Capital gains report 2024–25',
            'Pat Example (pat@example.com)',
            '1 July 2024 to 30 June 2025',
            // Midday on 1 August 2025 in Melbourne.
            '1 Aug 2025, 12:00pm (Australia/Melbourne)',
            'A working paper, not tax advice.',
            'Losses carried forward from earlier years',
            '$1,000.50',
            '$1,199.75',
            'Crypto: disposals in 2024–25',
            'Other assets: disposals in 2024–25',
            '10 Jan 2023',
            '-$2,000.00',
            'Over 12 months',
            '12 months or less',
            'class="section"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        $this->assertStringNotContainsString('Estimated', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('http', $html);
    }

    public function test_the_pdf_marks_and_counts_the_estimated_disposals(): void
    {
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->buy('1', '5000')->estimated());
        $this->add($this->bitcoin, '2024-11-01', Transaction::factory()->sell('1', '6000'));
        $report = app(CgtCalculator::class)->forYear($this->user, new FinancialYear(2025));

        $html = app(TaxReportPdf::class)->html($this->user, $report);

        $this->assertStringContainsString('1 disposal used a parcel with an estimated cost', $html);
        $this->assertStringContainsString('12 months or less · Estimated', $html);
        $this->assertStringContainsString('<tr class="estimated">', $html);
        $this->assertStringContainsString('Nothing was sold in this section in 2024–25.', $html);
    }

    public function test_a_closed_year_or_a_guest_gets_no_pdf(): void
    {
        $this->ledger();

        $this->get('/tax-report/2024/pdf')->assertRedirect(route('tax-report.index'));
        $this->get('/tax-report/1999/pdf')->assertNotFound();

        auth()->logout();

        $this->get('/tax-report/2025/pdf')->assertRedirect(route('login'));
    }

    public function test_the_pdf_is_the_users_own(): void
    {
        $this->ledger();
        $theirs = Asset::factory()->create(['name' => 'Their coin']);
        $this->add($theirs, '2024-08-01', Transaction::factory()->buy('1', '100'));
        $this->add($theirs, '2024-11-01', Transaction::factory()->sell('1', '99999'));
        $report = app(CgtCalculator::class)->forYear($this->user, new FinancialYear(2025));

        $this->assertStringNotContainsString('Their coin', app(TaxReportPdf::class)->html($this->user, $report));
    }

    // The asset classes in the report

    /**
     * The ledger, and a sale of a stamp collection (class "other") for a $400 short-held gain.
     */
    private function ledgerWithStamps(): Asset
    {
        $this->ledger();
        $stamps = Asset::factory()->for($this->user)->create(['name' => 'Stamps', 'class' => AssetClass::Other, 'unit' => 'item']);
        $this->add($stamps, '2024-08-01', Transaction::factory()->buy('1', '100'));
        $this->add($stamps, '2025-01-10', Transaction::factory()->sell('1', '500'));

        return $stamps;
    }

    public function test_a_report_takes_in_every_asset_class_until_some_are_chosen(): void
    {
        $this->ledgerWithStamps();

        $this->get('/tax-report/2025')
            ->assertOk()
            ->assertSeeInOrder(['Asset classes in the report', 'value="crypto" checked', 'value="metal" checked', 'value="other" checked'], false)
            ->assertDontSee('left-out-notice')
            // $1,900 of short-held and $3,900 of long-held gains, less $2,000: $3,800 left to halve.
            ->assertSeeInOrder(['Net capital gain', '$1,900.00']);
    }

    public function test_the_classes_chosen_are_saved_for_the_year_and_the_rest_are_left_out_of_everything(): void
    {
        $this->ledgerWithStamps();

        $this->put('/tax-report/2025', ['losses_carried_forward' => '0', 'asset_classes' => ['', 'crypto', 'other']])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tax-report.show', 2025));

        $this->assertSame(['crypto', 'other'], $this->user->taxYears()->where('year', 2025)->sole()->asset_classes);

        // Without the gold's $900: $1,900 short-held and $3,000 long-held, less $2,000, leaves $2,900 to halve.
        $this->get('/tax-report/2025')
            ->assertSeeInOrder(['id="left-out-notice"', 'Metal is left out of this report.'], false)
            ->assertSeeInOrder(['value="crypto" checked', 'value="metal">', 'value="other" checked'], false)
            ->assertSeeInOrder(['Gains on assets held longer than 12 months', '$3,000.00', '$0.00', '$3,000.00'])
            ->assertSeeInOrder(['Net capital gain', '$1,450.00'])
            ->assertSeeInOrder(['Other assets', 'Other only', 'id="disposals-other"', 'Stamps', '1 disposal'], false)
            ->assertDontSee('3 Mar 2025');
    }

    public function test_a_section_with_every_class_left_out_says_so(): void
    {
        $this->ledgerWithStamps();

        $this->put('/tax-report/2025', ['losses_carried_forward' => '0', 'asset_classes' => ['', 'crypto']]);

        $this->get('/tax-report/2025')
            ->assertSee('Metal and Other are left out of this report.')
            ->assertSeeInOrder(['id="disposals-other"', 'This section is left out of the report.'], false)
            ->assertDontSee('Stamps')
            // Crypto alone: $1,500 short-held and $3,000 long-held, less $2,000.
            ->assertSeeInOrder(['Net capital gain', '$1,250.00']);
    }

    public function test_every_class_can_be_chosen_again_in_any_order(): void
    {
        $this->ledgerWithStamps();
        $this->put('/tax-report/2025', ['losses_carried_forward' => '0', 'asset_classes' => ['', 'crypto']]);

        $this->put('/tax-report/2025', ['losses_carried_forward' => '0', 'asset_classes' => ['', 'other', 'metal', 'crypto']])->assertSessionHasNoErrors();

        $this->assertSame(['crypto', 'metal', 'other'], $this->user->taxYears()->where('year', 2025)->sole()->asset_classes);
        $this->get('/tax-report/2025')->assertDontSee('left-out-notice')->assertSeeInOrder(['Net capital gain', '$1,900.00']);
    }

    public function test_a_report_is_of_crypto_only_until_its_classes_are_chosen(): void
    {
        config(['nugget.tax.default_asset_classes' => ['crypto']]);
        $this->ledgerWithStamps();

        // Crypto alone: $1,500 short-held and $3,000 long-held, less $2,000.
        $this->get('/tax-report/2025')
            ->assertOk()
            ->assertSee('Metal and Other are left out of this report.')
            ->assertSeeInOrder(['value="crypto" checked', 'value="metal">', 'value="other">'], false)
            ->assertSee('A report takes in Crypto until you choose.')
            ->assertSeeInOrder(['Net capital gain', '$1,250.00'])
            ->assertSeeInOrder(['id="disposals-other"', 'This section is left out of the report.'], false);

        // Saving the losses alone does not turn the default into a choice.
        $this->put('/tax-report/2025', ['losses_carried_forward' => '10']);
        $this->assertNull($this->user->taxYears()->where('year', 2025)->sole()->asset_classes);

        $report = app(CgtCalculator::class)->forYear($this->user, new FinancialYear(2025));
        $this->assertSame([AssetClass::Crypto], $report->classes);
        $this->assertStringContainsString('Crypto (Metal and Other left out)', app(TaxReportPdf::class)->html($this->user, $report));
    }

    public function test_every_class_chosen_stays_chosen_whatever_the_default(): void
    {
        config(['nugget.tax.default_asset_classes' => ['crypto']]);
        $this->ledgerWithStamps();

        $this->put('/tax-report/2025', ['losses_carried_forward' => '0', 'asset_classes' => ['', 'crypto', 'metal', 'other']]);

        $this->get('/tax-report/2025')->assertDontSee('left-out-notice')->assertSeeInOrder(['Net capital gain', '$1,900.00']);
    }

    public function test_the_default_is_crypto_only_as_configured(): void
    {
        $this->app['config']->set('nugget', require config_path('nugget.php'));

        $this->assertSame([AssetClass::Crypto], TaxYear::defaultClasses());
    }

    public function test_a_report_needs_at_least_one_class_and_only_real_ones(): void
    {
        $this->ledgerWithStamps();
        $this->put('/tax-report/2025', ['losses_carried_forward' => '0', 'asset_classes' => ['', 'crypto']]);

        $this->from('/tax-report/2025')->put('/tax-report/2025', ['losses_carried_forward' => '5', 'asset_classes' => ['']])
            ->assertSessionHasErrors(['asset_classes' => 'Choose at least one asset class for the report.']);
        $this->put('/tax-report/2025', ['losses_carried_forward' => '5', 'asset_classes' => ['', 'shares']])->assertSessionHasErrors('asset_classes.0');
        $this->put('/tax-report/2025', ['losses_carried_forward' => '5', 'asset_classes' => 'crypto'])->assertSessionHasErrors('asset_classes');

        // Nothing of a refused form is saved.
        $saved = $this->user->taxYears()->where('year', 2025)->sole();
        $this->assertSame(['crypto'], $saved->asset_classes);
        $this->assertSame('0.00', $saved->losses_carried_forward->toFixed(2));
    }

    public function test_saving_the_losses_alone_leaves_the_classes_as_they_were(): void
    {
        $this->ledgerWithStamps();
        $this->put('/tax-report/2025', ['losses_carried_forward' => '0', 'asset_classes' => ['', 'crypto']]);

        $this->put('/tax-report/2025', ['losses_carried_forward' => '250'])->assertSessionHasNoErrors();

        $saved = $this->user->taxYears()->where('year', 2025)->sole();
        $this->assertSame(['crypto'], $saved->asset_classes);
        $this->assertSame('250.00', $saved->losses_carried_forward->toFixed(2));
    }

    public function test_each_year_and_each_user_has_their_own_classes(): void
    {
        $this->ledgerWithStamps();
        $this->add($this->gold, '2025-07-10', Transaction::factory()->sell('1', '3500'));
        $other = User::factory()->create();
        $other->taxYears()->create(['year' => 2026, 'asset_classes' => ['crypto']]);

        $this->put('/tax-report/2025', ['losses_carried_forward' => '0', 'asset_classes' => ['', 'crypto']]);

        // 2025–26 still takes in the gold: half of its $1,000 long-held gain.
        $this->get('/tax-report/2026')->assertDontSee('left-out-notice')->assertSeeInOrder(['Net capital gain', '$500.00']);
        $this->assertSame(['crypto'], $other->taxYears()->sole()->asset_classes);
    }

    public function test_the_calculator_can_be_told_the_classes_whatever_is_saved(): void
    {
        $this->ledgerWithStamps();
        $this->user->taxYears()->create(['year' => 2025, 'asset_classes' => ['crypto']]);
        $calculator = app(CgtCalculator::class);
        $year = new FinancialYear(2025);

        $saved = $calculator->forYear($this->user, $year);
        $metal = $calculator->forYear($this->user, $year, classes: [AssetClass::Metal]);

        $this->assertSame([AssetClass::Crypto], $saved->classes);
        $this->assertSame([AssetClass::Metal, AssetClass::Other], $saved->leftOut());
        $this->assertTrue($saved->takesIn(TaxSection::Crypto));
        $this->assertFalse($saved->takesIn(TaxSection::Other));
        $this->assertSame('1250.00', $saved->summary->netGain->toFixed(2));

        // The gold alone: half of $900.
        $this->assertSame('450.00', $metal->summary->netGain->toFixed(2));
        $this->assertSame(1, $metal->summary->sections[1]->disposals);
        $this->assertSame(0, $metal->summary->sections[0]->disposals);
    }

    public function test_the_pdf_follows_the_classes_chosen_and_says_what_is_left_out(): void
    {
        $this->ledgerWithStamps();
        $this->put('/tax-report/2025', ['losses_carried_forward' => '0', 'asset_classes' => ['', 'crypto', 'other']]);
        $report = app(CgtCalculator::class)->forYear($this->user, new FinancialYear(2025));

        $html = app(TaxReportPdf::class)->html($this->user, $report);

        $this->assertStringContainsString('Crypto, Other (Metal left out)', $html);
        $this->assertStringContainsString('Metal is left out of this report.', $html);
        $this->assertStringContainsString('Stamps', $html);
        $this->assertStringContainsString('$1,450.00', $html);
        $this->assertStringNotContainsString('3 Mar 2025', $html);

        $all = app(TaxReportPdf::class)->html($this->user, app(CgtCalculator::class)->forYear($this->user, new FinancialYear(2025), classes: AssetClass::cases()));

        $this->assertStringContainsString('<td>Crypto, Metal, Other</td>', $all);
        $this->assertStringNotContainsString('left out', $all);
    }

    // The CSV

    /**
     * The rows of the year's CSV download.
     *
     * @return list<list<string>>
     */
    private function csv(int $year = 2025): array
    {
        $content = $this->get("/tax-report/{$year}/csv")->assertOk()->streamedContent();

        $this->assertStringStartsWith("\u{FEFF}", $content, 'The file starts with a byte order mark');

        return array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), explode("\n", trim(substr($content, 3))));
    }

    public function test_the_csv_has_a_row_for_each_disposal_with_every_column_of_the_page(): void
    {
        $this->ledger();

        $this->get('/tax-report/2025')->assertSee('href="'.route('tax-report.csv', 2025).'"', false);

        $this->assertSame([
            ['Financial year', 'Section', 'Asset', 'Symbol', 'Class', 'Acquired', 'Sold', 'Units', 'Unit', 'Cost base', 'Proceeds', 'Gain or loss', 'Held over 12 months', 'Discount applies', 'Estimated cost'],
            ['2024–25', 'Crypto', 'Bitcoin', $this->bitcoin->symbol, 'Crypto', '2023-01-10', '2024-10-01', '1', 'BTC', '1000.00', '4000.00', '3000.00', 'Yes', 'Yes', 'No'],
            ['2024–25', 'Crypto', 'Bitcoin', $this->bitcoin->symbol, 'Crypto', '2024-08-01', '2024-11-01', '1', 'BTC', '5000.00', '6500.00', '1500.00', 'No', 'No', 'No'],
            ['2024–25', 'Crypto', 'Bitcoin', $this->bitcoin->symbol, 'Crypto', '2024-09-01', '2025-02-01', '1', 'BTC', '9000.00', '7000.00', '-2000.00', 'No', 'No', 'No'],
            ['2024–25', 'Other assets', 'Gold', $this->gold->symbol, 'Metal', '2022-05-05', '2025-03-03', '1', 'oz', '2500.00', '3400.00', '900.00', 'Yes', 'Yes', 'No'],
        ], $this->csv());
    }

    public function test_the_csv_is_a_streamed_download_named_for_the_year(): void
    {
        $this->ledger();

        $response = $this->get('/tax-report/2025/csv')->assertOk();

        $response->assertStreamed();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=nugget-capital-gains-2024-25.csv');
    }

    public function test_the_csv_gives_dates_by_the_users_calendar_and_marks_estimates_and_long_held_losses(): void
    {
        // 23:30 on 9 March in Melbourne, and 00:30 on 11 March a year later.
        Transaction::factory()->for($this->bitcoin)->buy('1.5', '9000')->estimated()->create(['occurred_at' => '2024-03-09 12:30:00']);
        Transaction::factory()->for($this->bitcoin)->sell('0.12345678', '4000')->create(['occurred_at' => '2025-03-10 13:30:00']);
        app(ParcelMatcher::class)->rematch($this->bitcoin);

        $row = $this->csv()[1];

        // Units to their eight places; a loss on a long-held parcel gets no discount.
        $this->assertSame(['2024-03-09', '2025-03-11', '0.12345678'], array_slice($row, 5, 3));
        $this->assertSame(['1111.11', '493.83', '-617.28', 'Yes', 'No', 'Yes'], array_slice($row, 9));
    }

    public function test_the_csv_follows_the_asset_classes_chosen(): void
    {
        $this->ledger();
        $this->put('/tax-report/2025', ['losses_carried_forward' => '0', 'asset_classes' => ['', 'metal']]);

        $rows = $this->csv();

        $this->assertCount(2, $rows);
        $this->assertSame('Gold', $rows[1][2]);
    }

    public function test_a_year_with_no_disposals_is_the_headings_alone(): void
    {
        $this->ledger();

        $this->assertSame([TaxReportCsv::HEADINGS], $this->csv(2026));
    }

    public function test_a_name_that_would_be_read_as_a_formula_is_made_text(): void
    {
        $this->bitcoin->update(['name' => '=HYPERLINK("http://example.com","Bitcoin")']);
        $this->gold->update(['name' => 'Gold, "bars"']);
        $this->ledger();

        $rows = $this->csv();

        $this->assertSame('\'=HYPERLINK("http://example.com","Bitcoin")', $rows[1][2]);
        // A comma and quotes are the CSV's own business, and a negative amount stays a number.
        $this->assertSame('Gold, "bars"', $rows[4][2]);
        $this->assertSame('-2000.00', $rows[3][11]);
    }

    public function test_a_closed_year_or_a_guest_gets_no_csv_and_it_is_the_users_own(): void
    {
        $this->ledger();
        $theirs = Asset::factory()->create(['name' => 'Their coin', 'class' => AssetClass::Crypto]);
        $this->add($theirs, '2024-08-01', Transaction::factory()->buy('1', '100'));
        $this->add($theirs, '2024-11-01', Transaction::factory()->sell('1', '99999'));

        $this->assertNotContains('Their coin', array_column($this->csv(), 2));
        $this->get('/tax-report/2024/csv')->assertRedirect(route('tax-report.index'));
        $this->get('/tax-report/1999/csv')->assertNotFound();

        auth()->logout();

        $this->get('/tax-report/2025/csv')->assertRedirect(route('login'));
    }
}
