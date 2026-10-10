<?php

namespace Tests\Feature\Ledger;

use App\Facades\Settings;
use App\Ledger\ParcelMatcher;
use App\Ledger\ReportYear;
use App\Ledger\ReportYears;
use App\Models\Asset;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The financial years that have disposals. The user is in Melbourne, 10
 * hours ahead of UTC in winter, and the first year reported on is 2024–25.
 */
class ReportYearsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Asset $bitcoin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->bitcoin = Asset::factory()->for($this->user)->create();
        Settings::set('first_report_year', 2025);

        Transaction::factory()->for($this->bitcoin)->buy('100', '10')->create(['occurred_at' => '2020-01-10 02:00:00']);
    }

    /**
     * Sell one unit at this moment in UTC.
     */
    private function sell(string $at, ?Asset $asset = null): void
    {
        $asset ??= $this->bitcoin;
        Transaction::factory()->for($asset)->sell('1', '20')->create(['occurred_at' => $at]);
        app(ParcelMatcher::class)->rematch($asset);
    }

    /**
     * The years as "label disposals open|closed".
     *
     * @return list<string>
     */
    private function years(?User $user = null): array
    {
        return app(ReportYears::class)->forUser($user ?? $this->user)
            ->map(fn (ReportYear $year) => "{$year->year->label()} {$year->disposals} ".($year->open ? 'open' : 'closed'))
            ->all();
    }

    public function test_a_user_with_no_disposals_has_no_years(): void
    {
        $this->assertSame([], $this->years());
    }

    public function test_each_year_with_a_disposal_is_listed_the_latest_first_with_its_count(): void
    {
        $this->sell('2024-03-04 02:00:00');
        $this->sell('2024-12-24 02:00:00');
        $this->sell('2025-02-01 02:00:00');
        $this->sell('2026-08-15 02:00:00');

        // A year with no disposal, 2025–26, is not in the list.
        $this->assertSame(['2026–27 1 open', '2024–25 2 open', '2023–24 1 closed'], $this->years());
    }

    public function test_a_sale_on_the_last_day_of_june_is_in_the_year_that_is_ending(): void
    {
        // 23:59:59 on 30 June 2025 in Melbourne.
        $this->sell('2025-06-30 13:59:59');

        $this->assertSame(['2024–25 1 open'], $this->years());
    }

    public function test_a_sale_on_the_first_day_of_july_is_in_the_year_that_is_starting(): void
    {
        // 00:00:00 on 1 July 2025 in Melbourne, which is still 30 June in UTC.
        $this->sell('2025-06-30 14:00:00');

        $this->assertSame(['2025–26 1 open'], $this->years());
    }

    public function test_the_year_turns_at_the_users_own_midnight(): void
    {
        $this->sell('2025-06-30 15:00:00');
        $this->assertSame(['2025–26 1 open'], $this->years());

        // The same moment is 23:00 on 30 June in Perth.
        $this->user->update(['timezone' => 'Australia/Perth']);
        $this->assertSame(['2024–25 1 open'], $this->years());
    }

    public function test_a_sale_that_uses_two_parcels_counts_two_disposals(): void
    {
        Transaction::factory()->for($this->bitcoin)->buy('1', '10')->create(['occurred_at' => '2019-01-10 02:00:00']);
        Transaction::factory()->for($this->bitcoin)->sell('3', '20')->create(['occurred_at' => '2024-08-01 02:00:00']);
        app(ParcelMatcher::class)->rematch($this->bitcoin);

        $this->assertSame(['2024–25 2 open'], $this->years());
    }

    public function test_a_year_before_the_first_report_year_is_closed(): void
    {
        $this->sell('2024-06-30 02:00:00');
        $this->sell('2024-07-01 02:00:00');

        $this->assertSame(['2024–25 1 open', '2023–24 1 closed'], $this->years());

        Settings::set('first_report_year', 2024);
        $this->assertSame(['2024–25 1 open', '2023–24 1 open'], $this->years());

        Settings::set('first_report_year', 2026);
        $this->assertSame(['2024–25 1 closed', '2023–24 1 closed'], $this->years());
    }

    public function test_the_open_years_are_the_ones_a_report_is_offered_for(): void
    {
        $this->sell('2024-03-04 02:00:00');
        $this->sell('2024-12-24 02:00:00');
        $this->sell('2026-08-15 02:00:00');

        $open = app(ReportYears::class)->open($this->user);

        $this->assertSame([2027, 2025], $open->map(fn (ReportYear $year) => $year->year->year)->all());
    }

    public function test_another_users_disposals_are_left_out(): void
    {
        $theirs = Asset::factory()->create();
        Transaction::factory()->for($theirs)->buy('5', '10')->create(['occurred_at' => '2020-01-10 02:00:00']);
        $this->sell('2022-08-15 02:00:00', $theirs);
        $this->sell('2024-12-24 02:00:00');

        $this->assertSame(['2024–25 1 open'], $this->years());
        $this->assertSame(['2022–23 1 closed'], $this->years($theirs->user));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_disposals(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ReportYears::class)->forUser($this->user);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $this->sell('2024-12-24 02:00:00');
        $forOne = $count();
        $this->sell('2025-12-24 02:00:00');
        $this->sell('2026-12-24 02:00:00');

        $this->assertSame($forOne, $count());
    }
}
