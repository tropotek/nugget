<?php

namespace Tests\Feature\Ledger;

use App\Ledger\ValuePoint;
use App\Ledger\ValueSeries;
use App\Models\Asset;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Worked examples for the value of a holding and of the portfolio over time.
 * The owner is in Melbourne, 10 hours ahead of UTC in June.
 */
class ValueSeriesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Asset $bitcoin;

    private ValueSeries $series;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->bitcoin = $this->asset('Bitcoin');
        $this->series = app(ValueSeries::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function asset(string $name, array $attributes = []): Asset
    {
        return Asset::factory()->for($this->user)->create(['name' => $name, ...$attributes]);
    }

    /**
     * @param  string  $at  a moment in UTC
     */
    private function add(Asset $asset, string $at, TransactionFactory $transaction): Transaction
    {
        return $transaction->for($asset)->create(['occurred_at' => $at]);
    }

    private function day(Asset $asset, string $date, string $bid): void
    {
        Price::factory()->for($asset)->day($date)->quoted($bid, $bid)->create();
    }

    private function intraday(Asset $asset, string $at, string $bid): void
    {
        Price::factory()->for($asset)->at($at)->quoted($bid, $bid)->create();
    }

    /**
     * The points of a daily series as date => value.
     *
     * @param  Collection<int, ValuePoint>  $points
     * @return array<string, string>
     */
    private function byDate(Collection $points): array
    {
        return $points->mapWithKeys(fn (ValuePoint $point) => [$point->at->toDateString() => $point->value->toFixed(2)])->all();
    }

    /**
     * The points of an intraday series as time => value.
     *
     * @param  Collection<int, ValuePoint>  $points
     * @return array<string, string>
     */
    private function byTime(Collection $points): array
    {
        return $points->mapWithKeys(fn (ValuePoint $point) => [$point->at->format('d H:i') => $point->value->toFixed(2)])->all();
    }

    // Daily

    public function test_each_day_is_valued_with_the_units_held_that_day(): void
    {
        $this->add($this->bitcoin, '2025-06-10 02:00:00', Transaction::factory()->buy('2', '100'));
        $this->add($this->bitcoin, '2025-06-12 02:00:00', Transaction::factory()->buy('1', '100'));
        $this->add($this->bitcoin, '2025-06-14 02:00:00', Transaction::factory()->sell('2.5', '100'));

        foreach (['09' => '90', '10' => '100', '11' => '110', '12' => '120', '13' => '130', '14' => '140', '15' => '150'] as $day => $bid) {
            $this->day($this->bitcoin, "2025-06-{$day}", $bid);
        }

        $points = $this->series->daily($this->bitcoin, '2025-06-09', '2025-06-15');

        // Not today's 0.5 units at every price, as tkbtc's v_asset_daily had it.
        $this->assertSame([
            '2025-06-09' => '0.00',
            '2025-06-10' => '200.00',
            '2025-06-11' => '220.00',
            '2025-06-12' => '360.00',
            '2025-06-13' => '390.00',
            '2025-06-14' => '70.00',
            '2025-06-15' => '75.00',
        ], $this->byDate($points));
    }

    public function test_a_transaction_counts_from_its_date_in_the_owners_timezone(): void
    {
        // 23:30 on the 11th in Melbourne, and 00:30 on the 13th.
        $this->add($this->bitcoin, '2025-06-11 13:30:00', Transaction::factory()->buy('1', '100'));
        $this->add($this->bitcoin, '2025-06-12 14:30:00', Transaction::factory()->buy('1', '100'));

        foreach (['10', '11', '12', '13'] as $day) {
            $this->day($this->bitcoin, "2025-06-{$day}", '100');
        }

        $this->assertSame(
            ['2025-06-10' => '0.00', '2025-06-11' => '100.00', '2025-06-12' => '100.00', '2025-06-13' => '200.00'],
            $this->byDate($this->series->daily($this->bitcoin, '2025-06-10', '2025-06-13')),
        );
    }

    public function test_a_daily_series_keeps_to_its_dates_and_has_a_point_per_priced_day(): void
    {
        $this->add($this->bitcoin, '2025-06-01 02:00:00', Transaction::factory()->buy('1', '100'));

        foreach (['09' => '90', '10' => '100', '12' => '120', '13' => '130'] as $day => $bid) {
            $this->day($this->bitcoin, "2025-06-{$day}", $bid);
        }
        $this->intraday($this->bitcoin, '2025-06-11 02:00:00', '999');

        $points = $this->series->daily($this->bitcoin, '2025-06-10', '2025-06-12');

        $this->assertSame(['2025-06-10' => '100.00', '2025-06-12' => '120.00'], $this->byDate($points));
        $this->assertTrue($points->first()->at->equalTo(Price::day('2025-06-10')));
    }

    public function test_a_daily_series_with_no_start_goes_back_to_the_first_price(): void
    {
        $this->add($this->bitcoin, '2025-06-01 02:00:00', Transaction::factory()->buy('1', '100'));
        $this->day($this->bitcoin, '2024-01-01', '50');
        $this->day($this->bitcoin, '2025-06-12', '120');
        $this->day($this->bitcoin, '2025-06-20', '200');

        $this->assertSame(
            ['2024-01-01' => '0.00', '2025-06-12' => '120.00'],
            $this->byDate($this->series->daily($this->bitcoin, null, '2025-06-12')),
        );
    }

    public function test_dates_given_as_moments_are_read_in_the_owners_timezone(): void
    {
        $this->add($this->bitcoin, '2025-06-01 02:00:00', Transaction::factory()->buy('1', '100'));
        $this->day($this->bitcoin, '2025-06-10', '100');
        $this->day($this->bitcoin, '2025-06-11', '110');
        $this->day($this->bitcoin, '2025-06-12', '120');

        // 20:00 UTC on the 10th is the 11th in Melbourne.
        $points = $this->series->daily($this->bitcoin, CarbonImmutable::parse('2025-06-10 20:00:00'), CarbonImmutable::parse('2025-06-10 20:00:00'));

        $this->assertSame(['2025-06-11' => '110.00'], $this->byDate($points));
    }

    public function test_the_portfolio_adds_up_the_assets_that_count_toward_the_total(): void
    {
        $gold = $this->asset('Gold');
        $coins = $this->asset('Coin collection', ['in_total' => false]);
        $theirs = Asset::factory()->create();

        $this->add($this->bitcoin, '2025-06-10 02:00:00', Transaction::factory()->buy('2', '100'));
        $this->add($gold, '2025-06-11 02:00:00', Transaction::factory()->buy('1', '1000'));
        $this->add($coins, '2025-06-10 02:00:00', Transaction::factory()->buy('1', '5000'));
        $this->add($theirs, '2025-06-10 02:00:00', Transaction::factory()->buy('1', '7000'));

        foreach (['10', '11', '12'] as $day) {
            $this->day($this->bitcoin, "2025-06-{$day}", '100');
            $this->day($gold, "2025-06-{$day}", '1000');
            $this->day($coins, "2025-06-{$day}", '5000');
            $this->day($theirs, "2025-06-{$day}", '7000');
        }

        $this->assertSame(
            ['2025-06-10' => '200.00', '2025-06-11' => '1200.00', '2025-06-12' => '1200.00'],
            $this->byDate($this->series->daily($this->user, '2025-06-10', '2025-06-12')),
        );
    }

    public function test_an_asset_with_no_price_on_a_day_keeps_its_last_one(): void
    {
        $gold = $this->asset('Gold');
        $this->add($this->bitcoin, '2025-06-01 02:00:00', Transaction::factory()->buy('2', '100'));
        $this->add($gold, '2025-06-01 02:00:00', Transaction::factory()->buy('1', '1000'));

        // Gold was last priced before the series starts, and again on the 12th.
        $this->day($gold, '2025-06-05', '1000');
        $this->day($gold, '2025-06-08', '1100');
        $this->day($gold, '2025-06-12', '1300');
        $this->day($this->bitcoin, '2025-06-10', '100');
        $this->day($this->bitcoin, '2025-06-11', '110');
        $this->day($this->bitcoin, '2025-06-12', '120');

        $this->assertSame(
            ['2025-06-10' => '1300.00', '2025-06-11' => '1320.00', '2025-06-12' => '1540.00'],
            $this->byDate($this->series->daily($this->user, '2025-06-10', '2025-06-12')),
        );
    }

    public function test_an_asset_is_left_out_until_it_has_a_price(): void
    {
        $gold = $this->asset('Gold');
        $this->add($this->bitcoin, '2025-06-01 02:00:00', Transaction::factory()->buy('2', '100'));
        $this->add($gold, '2025-06-01 02:00:00', Transaction::factory()->buy('1', '1000'));
        $this->day($this->bitcoin, '2025-06-10', '100');
        $this->day($this->bitcoin, '2025-06-11', '100');
        $this->day($gold, '2025-06-11', '1000');

        $this->assertSame(
            ['2025-06-10' => '200.00', '2025-06-11' => '1200.00'],
            $this->byDate($this->series->daily($this->user, '2025-06-10', '2025-06-11')),
        );
    }

    public function test_a_series_with_no_prices_is_empty(): void
    {
        $this->add($this->bitcoin, '2025-06-01 02:00:00', Transaction::factory()->buy('2', '100'));

        $this->assertTrue($this->series->daily($this->bitcoin, '2025-06-10', '2025-06-12')->isEmpty());
        $this->assertTrue($this->series->daily($this->user, null, '2025-06-12')->isEmpty());
        $this->assertTrue($this->series->intraday($this->user, CarbonImmutable::parse('2025-06-10'), CarbonImmutable::parse('2025-06-12'))->isEmpty());
    }

    // Intraday

    public function test_each_moment_is_valued_with_the_units_held_at_that_moment(): void
    {
        $this->add($this->bitcoin, '2025-06-10 02:00:00', Transaction::factory()->buy('2', '100'));
        $this->add($this->bitcoin, '2025-06-10 03:05:00', Transaction::factory()->sell('1', '100'));
        $this->add($this->bitcoin, '2025-06-10 03:12:00', Transaction::factory()->buy('3', '100'));

        foreach (['03:00' => '100', '03:05' => '110', '03:10' => '120', '03:15' => '130', '03:20' => '140'] as $time => $bid) {
            $this->intraday($this->bitcoin, "2025-06-10 {$time}:00", $bid);
        }
        $this->day($this->bitcoin, '2025-06-10', '999');

        $points = $this->series->intraday(
            $this->bitcoin,
            CarbonImmutable::parse('2025-06-10 03:00:00'),
            CarbonImmutable::parse('2025-06-10 03:15:00'),
        );

        // A sale at 03:05 has happened by the 03:05 price.
        $this->assertSame(
            ['10 03:00' => '200.00', '10 03:05' => '110.00', '10 03:10' => '120.00', '10 03:15' => '520.00'],
            $this->byTime($points),
        );
    }

    public function test_the_intraday_portfolio_adds_up_the_assets_and_carries_a_price_forward(): void
    {
        $gold = $this->asset('Gold');
        $coins = $this->asset('Coin collection', ['in_total' => false]);
        $this->add($this->bitcoin, '2025-06-01 02:00:00', Transaction::factory()->buy('2', '100'));
        $this->add($gold, '2025-06-01 02:00:00', Transaction::factory()->buy('1', '1000'));
        $this->add($coins, '2025-06-01 02:00:00', Transaction::factory()->buy('1', '5000'));

        $this->intraday($gold, '2025-06-10 02:55:00', '1000');
        $this->intraday($this->bitcoin, '2025-06-10 03:00:00', '100');
        $this->intraday($coins, '2025-06-10 03:00:00', '5000');
        $this->intraday($this->bitcoin, '2025-06-10 03:05:00', '110');
        $this->intraday($gold, '2025-06-10 03:05:00', '1100');
        // Gold's fetch failed at 03:10.
        $this->intraday($this->bitcoin, '2025-06-10 03:10:00', '120');

        $points = $this->series->intraday(
            $this->user,
            CarbonImmutable::parse('2025-06-10 03:00:00'),
            CarbonImmutable::parse('2025-06-10 04:00:00'),
        );

        $this->assertSame(
            ['10 03:00' => '1200.00', '10 03:05' => '1320.00', '10 03:10' => '1340.00'],
            $this->byTime($points),
        );
    }
}
