<?php

namespace Tests\Feature\Tax;

use App\Enums\AssetClass;
use App\Enums\TaxSection;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Decimal;
use App\Support\FinancialYear;
use App\Tax\CgtCalculator;
use App\Tax\CgtReport;
use App\Tax\CgtSection;
use Database\Factories\TransactionFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A year's capital gains worked from a ledger, through the parcel matcher.
 * The user is in Melbourne. Times are 02:00 UTC, midday there, unless said.
 */
class CgtCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Asset $bitcoin;

    private Asset $gold;

    protected function setUp(): void
    {
        parent::setUp();

        // Every class, where the default is crypto only: these are tests of the arithmetic across sections.
        config(['nugget.tax.default_asset_classes' => ['crypto', 'metal', 'other']]);

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->bitcoin = Asset::factory()->for($this->user)->create(['name' => 'Bitcoin', 'class' => AssetClass::Crypto]);
        $this->gold = Asset::factory()->for($this->user)->create(['name' => 'Gold', 'class' => AssetClass::Metal]);
    }

    private function add(Asset $asset, string $at, TransactionFactory $transaction): Transaction
    {
        $added = $transaction->for($asset)->create(['occurred_at' => strlen($at) === 10 ? "{$at} 02:00:00" : $at]);
        app(ParcelMatcher::class)->rematch($asset);

        return $added;
    }

    private function report(int $year, ?string $carriedForward = null, ?User $user = null): CgtReport
    {
        return app(CgtCalculator::class)->forYear(
            $user ?? $this->user,
            new FinancialYear($year),
            $carriedForward === null ? null : Decimal::of($carriedForward),
        );
    }

    /**
     * A section as [short gains, long gains, losses, disposals, estimated].
     *
     * @return list<string|int>
     */
    private function totals(CgtSection $section): array
    {
        return [$section->shortGains->toFixed(2), $section->longGains->toFixed(2), $section->losses->toFixed(2), $section->disposals, $section->estimated];
    }

    /**
     * The ledger behind most of the tests: 2024–25 has a short-held gain, a
     * long-held gain and a loss in crypto, and a long-held gain in gold.
     */
    private function ledger(): void
    {
        $this->add($this->bitcoin, '2023-01-10', Transaction::factory()->buy('1', '1000'));
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->buy('1', '5000'));
        $this->add($this->bitcoin, '2024-09-01', Transaction::factory()->buy('1', '9000'));
        // Sells the January 2023 parcel, held longer than 12 months: $4,000 against $1,000.
        $this->add($this->bitcoin, '2024-10-01', Transaction::factory()->sell('1', '4000'));
        // Sells the August 2024 parcel, held three months: $6,500 against $5,000.
        $this->add($this->bitcoin, '2024-11-01', Transaction::factory()->sell('1', '6500'));
        // Sells the September 2024 parcel at a loss: $7,000 against $9,000.
        $this->add($this->bitcoin, '2025-02-01', Transaction::factory()->sell('1', '7000'));

        $this->add($this->gold, '2022-05-05', Transaction::factory()->buy('2', '2500'));
        // Half the gold, held longer than 12 months: $3,400 against $2,500.
        $this->add($this->gold, '2025-03-03', Transaction::factory()->sell('1', '3400'));
    }

    public function test_a_year_is_totalled_by_section(): void
    {
        $this->ledger();

        $summary = $this->report(2025)->summary;

        $this->assertSame([TaxSection::Crypto, TaxSection::Other], array_column($summary->sections, 'section'));
        // Short-held gains, long-held gains, losses, disposal lines, estimated.
        $this->assertSame(['1500.00', '3000.00', '2000.00', 3, 0], $this->totals($summary->sections[0]));
        $this->assertSame(['0.00', '900.00', '0.00', 1, 0], $this->totals($summary->sections[1]));
    }

    public function test_the_net_gain_is_worked_from_both_sections(): void
    {
        $this->ledger();

        $summary = $this->report(2025)->summary;

        // $2,000 of losses: $1,500 clears the short-held gains and $500 comes off the $3,900 long-held.
        $this->assertSame('1500.00', $summary->shortGains->toFixed(2));
        $this->assertSame('3900.00', $summary->longGains->toFixed(2));
        $this->assertSame('2000.00', $summary->losses->toFixed(2));
        $this->assertSame('0.00', $summary->shortAfterLosses->toFixed(2));
        $this->assertSame('3400.00', $summary->longAfterLosses->toFixed(2));
        $this->assertSame('1700.00', $summary->discount->toFixed(2));
        $this->assertSame('1700.00', $summary->netGain->toFixed(2));
        $this->assertSame('0.00', $summary->lossCarriedForward->toFixed(2));
    }

    public function test_losses_carried_forward_reduce_the_net_gain(): void
    {
        $this->ledger();

        $summary = $this->report(2025, carriedForward: '1000')->summary;

        // $3,000 of losses in all: $2,400 of long-held gains are left to halve.
        $this->assertSame('1000.00', $summary->carriedForward->toFixed(2));
        $this->assertSame('1200.00', $summary->netGain->toFixed(2));
    }

    public function test_a_net_loss_year_carries_its_loss_forward(): void
    {
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->buy('2', '5000'));
        $this->add($this->bitcoin, '2024-11-01', Transaction::factory()->sell('1', '5300'));
        $this->add($this->bitcoin, '2025-02-01', Transaction::factory()->sell('1', '3000'));

        $summary = $this->report(2025, carriedForward: '250')->summary;

        // A $300 gain against a $2,000 loss and $250 from before.
        $this->assertSame('0.00', $summary->netGain->toFixed(2));
        $this->assertSame('0.00', $summary->discount->toFixed(2));
        $this->assertSame('1950.00', $summary->lossCarriedForward->toFixed(2));
    }

    public function test_the_disposals_are_listed_by_section_in_the_order_sold(): void
    {
        $this->ledger();

        $report = $this->report(2025);
        $crypto = $report->disposals(TaxSection::Crypto);

        $this->assertSame(['2024-10-01', '2024-11-01', '2025-02-01'], $crypto->map(fn (Disposal $d) => $d->transaction->occurred_at->toDateString())->all());
        $this->assertSame(['3000.00', '1500.00', '-2000.00'], $crypto->map(fn (Disposal $d) => $d->gain->toFixed(2))->all());
        $this->assertSame([true, false, false], $crypto->map(fn (Disposal $d) => $d->long_held)->all());
        $this->assertSame(['Gold'], $report->disposals(TaxSection::Other)->map(fn (Disposal $d) => $d->parcel->asset->name)->all());
    }

    public function test_a_sale_across_two_parcels_is_two_lines_each_a_gain_or_a_loss_of_its_own(): void
    {
        $this->add($this->bitcoin, '2023-01-10', Transaction::factory()->buy('1', '1000'));
        $this->add($this->bitcoin, '2024-09-01', Transaction::factory()->buy('1', '9000'));
        // $5,000 a unit: a long-held gain of $4,000 on the first parcel and a loss of $4,000 on the second.
        $this->add($this->bitcoin, '2024-12-01', Transaction::factory()->sell('2', '5000'));

        $summary = $this->report(2025)->summary;

        $this->assertSame(['0.00', '4000.00', '4000.00', 2, 0], $this->totals($summary->sections[0]));
        // The loss uses up the gain before any discount is given.
        $this->assertSame('0.00', $summary->netGain->toFixed(2));
        $this->assertSame('0.00', $summary->lossCarriedForward->toFixed(2));
    }

    public function test_a_parcel_is_long_held_from_the_day_after_a_year(): void
    {
        $this->add($this->bitcoin, '2023-08-01', Transaction::factory()->buy('2', '1000'));
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->sell('1', '1500'));
        $this->add($this->bitcoin, '2024-08-02', Transaction::factory()->sell('1', '1500'));

        $this->assertSame(['500.00', '500.00', '0.00', 2, 0], $this->totals($this->report(2025)->summary->sections[0]));
    }

    public function test_a_found_parcel_sold_is_all_gain(): void
    {
        $this->add($this->gold, '2024-09-21', Transaction::factory()->found('3'));
        $this->add($this->gold, '2025-01-15', Transaction::factory()->sell('3', '148.50'));

        $summary = $this->report(2025)->summary;

        $this->assertSame(['445.50', '0.00', '0.00', 1, 0], $this->totals($summary->sections[1]));
        $this->assertSame('445.50', $summary->netGain->toFixed(2));
    }

    public function test_disposals_that_used_an_estimated_parcel_are_counted(): void
    {
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->buy('1', '5000')->estimated());
        $this->add($this->bitcoin, '2024-08-02', Transaction::factory()->buy('1', '5000'));
        $this->add($this->bitcoin, '2024-11-01', Transaction::factory()->sell('2', '6000'));
        $this->add($this->gold, '2024-09-21', Transaction::factory()->buy('1', '100')->estimated());
        $this->add($this->gold, '2025-01-15', Transaction::factory()->sell('1', '150'));

        $report = $this->report(2025);

        $this->assertSame(1, $report->summary->sections[0]->estimated);
        $this->assertSame(1, $report->summary->sections[1]->estimated);
        $this->assertSame(2, $report->estimated());
    }

    public function test_only_the_sales_of_the_year_count_by_the_users_calendar(): void
    {
        $this->add($this->bitcoin, '2020-01-10', Transaction::factory()->buy('10', '100'));
        // 23:59:59 on 30 June 2024 in Melbourne, and midnight a second later.
        $this->add($this->bitcoin, '2024-06-30 13:59:59', Transaction::factory()->sell('1', '200'));
        $this->add($this->bitcoin, '2024-06-30 14:00:00', Transaction::factory()->sell('1', '300'));
        $this->add($this->bitcoin, '2025-06-30 13:59:59', Transaction::factory()->sell('1', '400'));
        $this->add($this->bitcoin, '2025-06-30 14:00:00', Transaction::factory()->sell('1', '500'));

        $this->assertSame('100.00', $this->report(2024)->summary->longGains->toFixed(2));
        // $200 and $300 of gain.
        $this->assertSame('500.00', $this->report(2025)->summary->longGains->toFixed(2));
        $this->assertSame(2, $this->report(2025)->summary->sections[0]->disposals);
        $this->assertSame('400.00', $this->report(2026)->summary->longGains->toFixed(2));
    }

    public function test_a_year_with_no_disposals_is_all_zeros(): void
    {
        $this->ledger();

        $report = $this->report(2023);

        $this->assertSame(['0.00', '0.00', '0.00', 0, 0], $this->totals($report->summary->sections[0]));
        $this->assertSame('0.00', $report->summary->netGain->toFixed(2));
        $this->assertTrue($report->disposals(TaxSection::Crypto)->isEmpty());
        $this->assertSame(0, $report->estimated());
    }

    public function test_another_users_disposals_are_left_out(): void
    {
        $this->ledger();
        $theirs = Asset::factory()->create();
        $this->add($theirs, '2024-08-01', Transaction::factory()->buy('1', '100'));
        $this->add($theirs, '2024-11-01', Transaction::factory()->sell('1', '99999'));

        $this->assertSame('1700.00', $this->report(2025)->summary->netGain->toFixed(2));
        $this->assertSame('99899.00', $this->report(2025, user: $theirs->user)->summary->netGain->toFixed(2));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_disposals(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $report = $this->report(2025);
            $report->disposals(TaxSection::Crypto)->each(fn (Disposal $d) => [$d->parcel->asset->name, $d->transaction->occurred_at]);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->add($this->bitcoin, '2020-01-10', Transaction::factory()->buy('10', '100'));
        $this->add($this->bitcoin, '2024-08-01', Transaction::factory()->sell('1', '200'));
        $forOne = $count();
        $this->add($this->bitcoin, '2024-09-01', Transaction::factory()->sell('1', '200'));
        $this->add($this->bitcoin, '2024-10-01', Transaction::factory()->sell('1', '200'));

        $this->assertSame($forOne, $count());
    }

    public function test_the_seeded_ledger_adds_up(): void
    {
        $this->app->make(DatabaseSeeder::class)->__invoke();
        $admin = User::where('email', 'admin@example.com')->sole();

        foreach ([2025, 2026, 2027] as $year) {
            $report = $this->report($year, user: $admin);
            $gains = Decimal::sum(collect($report->disposals)->flatten()->map(fn (Disposal $disposal) => $disposal->gain));

            // The gains less the losses are the sum of every line.
            $this->assertTrue(
                $report->summary->totalGains()->sub($report->summary->losses)->equals($gains),
                "The {$year} totals are not the sum of the lines",
            );
            $this->assertFalse($report->summary->netGain->isNegative());
        }
    }
}
