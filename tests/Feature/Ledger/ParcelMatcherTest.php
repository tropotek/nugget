<?php

namespace Tests\Feature\Ledger;

use App\Ledger\InsufficientUnits;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Parcel;
use App\Models\Transaction;
use App\Models\User;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Worked examples for the matcher. Times are UTC: 02:00 UTC is early
 * afternoon on the same day in Melbourne, the users' timezone here.
 */
class ParcelMatcherTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::factory()->create();
    }

    /**
     * Add a transaction to an asset (the test's own by default) at 02:00 UTC on a day, or at a full time.
     */
    private function add(string $when, TransactionFactory $transaction, ?Asset $asset = null): Transaction
    {
        return $transaction->for($asset ?? $this->asset)->create([
            'occurred_at' => strlen($when) === 10 ? "{$when} 02:00:00" : $when,
        ]);
    }

    private function rematch(?Asset $asset = null): void
    {
        app(ParcelMatcher::class)->rematch($asset ?? $this->asset);
    }

    /**
     * @return Collection<int, Parcel>
     */
    private function parcels(?Asset $asset = null): Collection
    {
        return ($asset ?? $this->asset)->parcels()->orderBy('acquired_at')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, Disposal>
     */
    private function disposals(Transaction $sale): Collection
    {
        return $sale->disposals()->get()->sortBy(fn (Disposal $d) => [$d->parcel->acquired_at, $d->parcel_id])->values();
    }

    /**
     * @return array<int, array<string, string|bool>>
     */
    private function rows(Collection $disposals): array
    {
        return $disposals->map(fn (Disposal $d) => [
            'units' => (string) $d->units,
            'cost_base' => $d->cost_base->toFixed(2),
            'proceeds' => $d->proceeds->toFixed(2),
            'gain' => $d->gain->toFixed(2),
            'long_held' => $d->long_held,
        ])->all();
    }

    // Acquisitions

    public function test_a_buy_becomes_a_parcel_whose_cost_base_includes_the_fee(): void
    {
        $buy = $this->add('2024-08-05', Transaction::factory()->buy('0.5', '100000', '50'));

        $this->rematch();

        $parcel = $this->parcels()->sole();
        $this->assertTrue($parcel->transaction->is($buy));
        $this->assertSame('2024-08-05 02:00:00', $parcel->acquired_at->toDateTimeString());
        $this->assertSame('0.5', (string) $parcel->units);
        $this->assertSame('0.5', (string) $parcel->units_remaining);
        $this->assertSame('50050.00', $parcel->cost_base->toFixed(2));
        $this->assertFalse($parcel->estimated);
    }

    public function test_a_find_a_reward_and_an_adjustment_up_each_become_a_parcel(): void
    {
        $this->add('2024-09-01', Transaction::factory()->found('2.35'));
        $this->add('2024-09-02', Transaction::factory()->reward('18.5', '0.86'));
        $this->add('2024-09-03', Transaction::factory()->adjust('0.25'));

        $this->rematch();

        $this->assertSame(
            [['2.35', '0.00'], ['18.5', '15.91'], ['0.25', '0.00']],
            $this->parcels()->map(fn (Parcel $p) => [(string) $p->units, $p->cost_base->toFixed(2)])->all(),
        );
    }

    public function test_an_estimated_acquisition_makes_an_estimated_parcel(): void
    {
        $this->add('2024-08-05', Transaction::factory()->buy('1', '100')->estimated());

        $this->rematch();

        $this->assertTrue($this->parcels()->sole()->estimated);
    }

    // Sales

    public function test_a_partial_sale_uses_part_of_a_parcel_and_the_fee_comes_off_the_proceeds(): void
    {
        $this->add('2024-08-05', Transaction::factory()->buy('1', '100'));
        $sale = $this->add('2024-10-01', Transaction::factory()->sell('0.4', '200', '2'));

        $this->rematch();

        $this->assertSame(
            [['units' => '0.4', 'cost_base' => '40.00', 'proceeds' => '78.00', 'gain' => '38.00', 'long_held' => false]],
            $this->rows($this->disposals($sale)),
        );

        $parcel = $this->parcels()->sole();
        $this->assertSame('0.6', (string) $parcel->units_remaining);
        $this->assertSame('100.00', $parcel->cost_base->toFixed(2));
        $this->assertSame('60.00', $parcel->remainingCostBase()->toFixed(2));
    }

    public function test_a_sale_across_three_parcels_uses_the_oldest_first_with_one_disposal_each(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-02-10', Transaction::factory()->buy('2', '150'));
        $this->add('2024-03-10', Transaction::factory()->buy('3', '200'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('4.5', '300', '13.50'));

        $this->rematch();

        $this->assertSame('1336.50', $sale->total->toFixed(2));
        $this->assertSame(
            [
                ['units' => '1', 'cost_base' => '100.00', 'proceeds' => '297.00', 'gain' => '197.00', 'long_held' => false],
                ['units' => '2', 'cost_base' => '300.00', 'proceeds' => '594.00', 'gain' => '294.00', 'long_held' => false],
                ['units' => '1.5', 'cost_base' => '300.00', 'proceeds' => '445.50', 'gain' => '145.50', 'long_held' => false],
            ],
            $this->rows($this->disposals($sale)),
        );
        $this->assertSame(['0', '0', '1.5'], $this->parcels()->map(fn (Parcel $p) => (string) $p->units_remaining)->all());
    }

    public function test_the_oldest_parcel_is_the_one_acquired_first_not_the_one_entered_first(): void
    {
        $this->add('2024-03-10', Transaction::factory()->buy('1', '300'));
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '500'));

        $this->rematch();

        $this->assertSame('100.00', $this->disposals($sale)->sole()->cost_base->toFixed(2));
    }

    public function test_a_later_sale_carries_on_from_where_the_last_one_stopped(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-02-10', Transaction::factory()->buy('1', '200'));
        $first = $this->add('2024-06-01', Transaction::factory()->sell('0.5', '400'));
        $second = $this->add('2024-07-01', Transaction::factory()->sell('1', '400'));

        $this->rematch();

        $this->assertSame([['0.5', '50.00']], $this->disposals($first)->map(fn (Disposal $d) => [(string) $d->units, $d->cost_base->toFixed(2)])->all());
        $this->assertSame(
            [['0.5', '50.00'], ['0.5', '100.00']],
            $this->disposals($second)->map(fn (Disposal $d) => [(string) $d->units, $d->cost_base->toFixed(2)])->all(),
        );
    }

    public function test_a_sale_below_cost_is_a_loss(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '200', '5'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '150', '5'));

        $this->rematch();

        $this->assertSame(
            [['units' => '1', 'cost_base' => '205.00', 'proceeds' => '145.00', 'gain' => '-60.00', 'long_held' => false]],
            $this->rows($this->disposals($sale)),
        );
    }

    public function test_a_parcel_that_cost_nothing_is_all_gain(): void
    {
        $this->add('2024-09-21', Transaction::factory()->found('5.1'));
        $sale = $this->add('2025-01-15', Transaction::factory()->sell('3', '148.50'));

        $this->rematch();

        $this->assertSame(
            [['units' => '3', 'cost_base' => '0.00', 'proceeds' => '445.50', 'gain' => '445.50', 'long_held' => false]],
            $this->rows($this->disposals($sale)),
        );
    }

    // Cents

    public function test_the_cost_of_a_parcel_sold_in_thirds_adds_up_to_the_cent(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('3', '33.33333333', '0.00000001'));
        $sales = [
            $this->add('2024-02-01', Transaction::factory()->sell('1', '50')),
            $this->add('2024-03-01', Transaction::factory()->sell('1', '50')),
            $this->add('2024-04-01', Transaction::factory()->sell('1', '50')),
        ];

        $this->rematch();

        $this->assertSame('100.00', $this->parcels()->sole()->cost_base->toFixed(2));
        $this->assertSame(
            ['33.33', '33.34', '33.33'],
            array_map(fn (Transaction $sale) => $this->disposals($sale)->sole()->cost_base->toFixed(2), $sales),
        );
    }

    public function test_the_proceeds_of_a_sale_split_in_thirds_add_up_to_the_cent(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '10'));
        $this->add('2024-01-11', Transaction::factory()->buy('1', '10'));
        $this->add('2024-01-12', Transaction::factory()->buy('1', '10'));
        $sale = $this->add('2024-02-01', Transaction::factory()->sell('3', '33.33333333', '0.00000001'));

        $this->rematch();

        $this->assertSame('100.00', $sale->total->toFixed(2));
        $this->assertSame(
            ['33.33', '33.34', '33.33'],
            $this->disposals($sale)->map(fn (Disposal $d) => $d->proceeds->toFixed(2))->all(),
        );
    }

    // The 12-month line

    public function test_a_parcel_sold_a_year_to_the_day_after_it_was_bought_is_not_long_held(): void
    {
        $this->add('2024-03-10', Transaction::factory()->buy('1', '100'));
        $sale = $this->add('2025-03-10', Transaction::factory()->sell('1', '150'));

        $this->rematch();

        $this->assertFalse($this->disposals($sale)->sole()->long_held);
    }

    public function test_a_parcel_sold_the_day_after_that_is_long_held(): void
    {
        $this->add('2024-03-10', Transaction::factory()->buy('1', '100'));
        $sale = $this->add('2025-03-11', Transaction::factory()->sell('1', '150'));

        $this->rematch();

        $this->assertTrue($this->disposals($sale)->sole()->long_held);
    }

    public function test_one_sale_can_span_the_12_month_line(): void
    {
        $this->add('2024-03-01', Transaction::factory()->buy('1', '100'));
        $this->add('2024-09-01', Transaction::factory()->buy('1', '120'));
        $sale = $this->add('2025-06-01', Transaction::factory()->sell('2', '200'));

        $this->rematch();

        $this->assertSame(
            [
                ['units' => '1', 'cost_base' => '100.00', 'proceeds' => '200.00', 'gain' => '100.00', 'long_held' => true],
                ['units' => '1', 'cost_base' => '120.00', 'proceeds' => '200.00', 'gain' => '80.00', 'long_held' => false],
            ],
            $this->rows($this->disposals($sale)),
        );
    }

    public function test_the_12_months_are_counted_in_the_owners_dates_not_utc_dates(): void
    {
        // 13:30 UTC on the 10th is already the 11th in Melbourne, so by the
        // owner's calendar this is bought and sold on the same date a year apart.
        $this->add('2024-03-10 13:30:00', Transaction::factory()->buy('1', '100'));
        $sale = $this->add('2025-03-11 02:00:00', Transaction::factory()->sell('1', '150'));

        $this->rematch();

        $this->assertFalse($this->disposals($sale)->sole()->long_held);
    }

    public function test_a_parcel_bought_on_29_february_is_long_held_from_1_march_the_next_year(): void
    {
        $this->add('2024-02-29', Transaction::factory()->buy('2', '100'));
        $early = $this->add('2025-02-28', Transaction::factory()->sell('1', '150'));
        $late = $this->add('2025-03-01', Transaction::factory()->sell('1', '150'));

        $this->rematch();

        $this->assertFalse($this->disposals($early)->sole()->long_held);
        $this->assertTrue($this->disposals($late)->sole()->long_held);
    }

    // Selling more than is held

    public function test_selling_more_than_is_held_is_refused_with_a_clear_message(): void
    {
        $this->asset->update(['name' => 'Bitcoin', 'unit' => 'BTC']);
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-06-01', Transaction::factory()->sell('1.5', '150'));

        try {
            $this->rematch();
            $this->fail('Selling more than is held was not refused.');
        } catch (InsufficientUnits $e) {
            $this->assertSame('Bitcoin: the sell of 1.5 BTC on 1 Jun 2024 is more than the 1 BTC held.', $e->getMessage());
        }
    }

    public function test_a_refused_rematch_leaves_the_parcels_and_disposals_as_they_were(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('0.5', '150'));
        $this->rematch();
        $before = [$this->parcels()->toJson(), $this->disposals($sale)->toJson()];

        $this->add('2024-07-01', Transaction::factory()->sell('5', '150'));

        try {
            $this->rematch();
            $this->fail('Selling more than is held was not refused.');
        } catch (InsufficientUnits) {
        }

        $this->assertSame($before, [$this->parcels()->toJson(), $this->disposals($sale)->toJson()]);
    }

    public function test_a_sale_before_the_buy_that_would_cover_it_is_refused(): void
    {
        $this->add('2024-06-01', Transaction::factory()->sell('1', '150'));
        $this->add('2024-07-01', Transaction::factory()->buy('1', '100'));

        $this->expectException(InsufficientUnits::class);

        $this->rematch();
    }

    public function test_a_buy_and_a_sale_at_the_same_moment_count_the_buy_first(): void
    {
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '150'));
        $this->add('2024-06-01', Transaction::factory()->buy('1', '100'));

        $this->rematch();

        $this->assertSame('50.00', $this->disposals($sale)->sole()->gain->toFixed(2));
    }

    // Adjustments down

    public function test_an_adjustment_down_takes_from_the_oldest_parcel_with_no_disposal(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-02-10', Transaction::factory()->buy('1', '200'));
        $this->add('2024-03-01', Transaction::factory()->adjust('-0.25'));

        $this->rematch();

        $this->assertSame(['0.75', '1'], $this->parcels()->map(fn (Parcel $p) => (string) $p->units_remaining)->all());
        $this->assertSame(0, Disposal::count());
    }

    public function test_an_adjustment_down_past_zero_is_refused(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-03-01', Transaction::factory()->adjust('-1.00000001'));

        $this->expectException(InsufficientUnits::class);

        $this->rematch();
    }

    // Transfers

    public function test_a_transfer_takes_the_cost_base_and_the_acquired_date_to_the_other_holding(): void
    {
        $wallet = Asset::factory()->for($this->asset->user)->create(['name' => 'Cold wallet']);
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2025-06-01', Transaction::factory()->transferOut('0.4'));
        $arrival = $this->add('2025-06-01', Transaction::factory()->transferIn('0.4'), $wallet);
        $sale = $this->add('2025-06-02', Transaction::factory()->sell('0.4', '500'), $wallet);

        $this->rematch();

        $this->assertSame('0.6', (string) $this->parcels()->sole()->units_remaining);
        $this->assertSame('60.00', $this->parcels()->sole()->remainingCostBase()->toFixed(2));

        $moved = $this->parcels($wallet)->sole();
        $this->assertTrue($moved->transaction->is($arrival));
        $this->assertSame('2024-01-10 02:00:00', $moved->acquired_at->toDateTimeString());
        $this->assertSame('0.4', (string) $moved->units);
        $this->assertSame('40.00', $moved->cost_base->toFixed(2));

        $this->assertSame(
            [['units' => '0.4', 'cost_base' => '40.00', 'proceeds' => '200.00', 'gain' => '160.00', 'long_held' => true]],
            $this->rows($this->disposals($sale)),
        );
        $this->assertSame(1, Disposal::count());
    }

    public function test_rematching_either_holding_rebuilds_both(): void
    {
        $wallet = Asset::factory()->for($this->asset->user)->create();
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2025-06-01', Transaction::factory()->transferOut('0.4'));
        $this->add('2025-06-01', Transaction::factory()->transferIn('0.4'), $wallet);

        $this->rematch($wallet);

        $this->assertSame('0.6', (string) $this->parcels()->sole()->units_remaining);
        $this->assertSame('40.00', $this->parcels($wallet)->sole()->cost_base->toFixed(2));
    }

    public function test_a_transfer_out_of_two_parcels_arrives_as_two_parcels(): void
    {
        $wallet = Asset::factory()->for($this->asset->user)->create();
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100')->estimated());
        $this->add('2024-02-10', Transaction::factory()->buy('1', '300'));
        $this->add('2024-06-01', Transaction::factory()->transferOut('1.5'));
        $this->add('2024-06-02', Transaction::factory()->transferIn('1.5'), $wallet);

        $this->rematch();

        $this->assertSame(
            [['2024-01-10', '1', '100.00', true], ['2024-02-10', '0.5', '150.00', false]],
            $this->parcels($wallet)->map(fn (Parcel $p) => [
                $p->acquired_at->toDateString(), (string) $p->units, $p->cost_base->toFixed(2), $p->estimated,
            ])->all(),
        );
    }

    public function test_less_can_arrive_than_left_and_the_rest_stays_out(): void
    {
        $wallet = Asset::factory()->for($this->asset->user)->create();
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-06-01', Transaction::factory()->transferOut('0.5'));
        $this->add('2024-06-02', Transaction::factory()->transferIn('0.4'), $wallet);

        $this->rematch();

        $this->assertSame('0.5', (string) $this->parcels()->sole()->units_remaining);
        $this->assertSame(['0.4', '40.00'], [(string) $this->parcels($wallet)->sole()->units, $this->parcels($wallet)->sole()->cost_base->toFixed(2)]);
    }

    public function test_units_can_come_back_to_the_holding_they_left(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-06-01', Transaction::factory()->transferOut('1'));
        $this->add('2024-09-01', Transaction::factory()->transferIn('1'));

        $this->rematch();

        $this->assertSame(
            [['2024-01-10', '1', '0'], ['2024-01-10', '1', '1']],
            $this->parcels()->map(fn (Parcel $p) => [$p->acquired_at->toDateString(), (string) $p->units, (string) $p->units_remaining])->all(),
        );
    }

    public function test_a_transfer_in_with_nothing_sent_first_starts_a_parcel_at_its_own_date_and_cost(): void
    {
        $this->add('2024-06-01', Transaction::factory()->transferIn('0.5')->estimated());

        $this->rematch();

        $parcel = $this->parcels()->sole();
        $this->assertSame('2024-06-01', $parcel->acquired_at->toDateString());
        $this->assertSame('0.00', $parcel->cost_base->toFixed(2));
        $this->assertTrue($parcel->estimated);
    }

    public function test_a_transfer_in_bigger_than_what_was_sent_starts_a_parcel_for_the_rest(): void
    {
        $wallet = Asset::factory()->for($this->asset->user)->create();
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-06-01', Transaction::factory()->transferOut('0.4'));
        $this->add('2024-06-02', Transaction::factory()->transferIn('1'), $wallet);

        $this->rematch();

        $this->assertSame(
            [['2024-01-10', '0.4', '40.00'], ['2024-06-02', '0.6', '0.00']],
            $this->parcels($wallet)->map(fn (Parcel $p) => [$p->acquired_at->toDateString(), (string) $p->units, $p->cost_base->toFixed(2)])->all(),
        );
    }

    public function test_a_transfer_does_not_reach_another_user_another_symbol_or_another_unit(): void
    {
        $this->asset->update(['symbol' => 'XAU', 'unit' => 'oz']);
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-06-01', Transaction::factory()->transferOut('1'));

        $others = [
            Asset::factory()->create(['symbol' => 'XAU', 'unit' => 'oz']),
            Asset::factory()->for($this->asset->user)->create(['symbol' => 'XAG', 'unit' => 'oz']),
            Asset::factory()->for($this->asset->user)->create(['symbol' => 'XAU', 'unit' => 'g']),
        ];

        foreach ($others as $other) {
            $this->add('2024-06-02', Transaction::factory()->transferIn('1'), $other);
            $this->rematch($other);

            $this->assertSame('2024-06-02', $this->parcels($other)->sole()->acquired_at->toDateString());
            $this->assertSame('0.00', $this->parcels($other)->sole()->cost_base->toFixed(2));
        }
    }

    // Rebuilding

    public function test_rematching_again_gives_the_same_parcels_and_disposals(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-02-10', Transaction::factory()->buy('2', '150'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1.5', '300'));
        $this->rematch();
        $first = [$this->rows($this->disposals($sale)), $this->parcels()->map->only(['units', 'units_remaining', 'cost_base'])->toJson()];

        $this->rematch();

        $this->assertSame($first, [$this->rows($this->disposals($sale)), $this->parcels()->map->only(['units', 'units_remaining', 'cost_base'])->toJson()]);
        $this->assertSame(2, Parcel::count());
        $this->assertSame(2, Disposal::count());
    }

    public function test_a_rematch_follows_a_change_to_the_ledger(): void
    {
        $buy = $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '300'));
        $this->rematch();

        $buy->update(['total' => '250']);
        $this->rematch();

        $this->assertSame('50.00', $this->disposals($sale)->sole()->gain->toFixed(2));
    }

    public function test_a_rematch_leaves_other_holdings_alone(): void
    {
        $other = Asset::factory()->for($this->asset->user)->create(['symbol' => 'ADA', 'unit' => 'ADA']);
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'), $other);
        $this->rematch($other);
        $parcelId = $this->parcels($other)->sole()->id;

        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->rematch();

        $this->assertSame($parcelId, $this->parcels($other)->sole()->id);
    }

    public function test_an_asset_with_no_transactions_has_no_parcels(): void
    {
        $this->rematch();

        $this->assertSame(0, Parcel::count());
    }

    public function test_the_owner_of_a_holding_in_another_timezone_gets_their_own_dates(): void
    {
        $perth = Asset::factory()->for(User::factory()->create(['timezone' => 'Australia/Perth']))->create();

        // 15:00 UTC on the 10th is 23:00 on the 10th in Perth, and 02:00 on the 11th in Melbourne.
        $this->add('2024-03-10 15:00:00', Transaction::factory()->buy('1', '100'), $perth);
        $sale = $this->add('2025-03-11 02:00:00', Transaction::factory()->sell('1', '150'), $perth);

        $this->rematch($perth);

        $this->assertTrue($this->disposals($sale)->sole()->long_held);
    }

    // The parcels a sale can be matched to

    /**
     * @return list<array{string, string, string}> each parcel's acquired day, units left and cost base left
     */
    private function openBefore(Transaction $sale): array
    {
        return app(ParcelMatcher::class)->openBefore($sale)
            ->map(fn (Parcel $p) => [$p->acquired_at->toDateString(), (string) $p->units_remaining, $p->remainingCostBase()->toFixed(2)])
            ->all();
    }

    public function test_the_parcels_open_before_a_sale_are_as_they_were_at_that_moment(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-02-10', Transaction::factory()->buy('2', '200'));
        $first = $this->add('2024-03-10', Transaction::factory()->sell('0.5', '300'));
        $this->add('2024-04-10', Transaction::factory()->adjust('-0.25'));
        $second = $this->add('2024-05-10', Transaction::factory()->sell('1', '300'));
        $this->add('2024-06-10', Transaction::factory()->buy('5', '250'));
        $this->add('2024-07-10', Transaction::factory()->sell('1', '300'));
        $this->rematch();

        // Nothing has been used yet, and the later buy is not there.
        $this->assertSame([['2024-01-10', '1', '100.00'], ['2024-02-10', '2', '400.00']], $this->openBefore($first));
        // The first sale and the adjustment have used 0.75 of the first parcel.
        $this->assertSame([['2024-01-10', '0.25', '25.00'], ['2024-02-10', '2', '400.00']], $this->openBefore($second));
    }

    public function test_reading_the_parcels_open_before_a_sale_changes_nothing(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $sale = $this->add('2024-03-10', Transaction::factory()->sell('0.5', '300'));
        $this->add('2024-04-10', Transaction::factory()->sell('0.25', '300'));
        $this->rematch();
        $rows = fn () => [DB::table('parcels')->get()->toJson(), DB::table('disposals')->get()->toJson()];
        $before = $rows();

        $this->openBefore($sale);

        $this->assertSame($before, $rows());
    }

    public function test_the_parcels_open_before_a_sale_include_what_a_transfer_brought(): void
    {
        $wallet = Asset::factory()->for($this->asset->user)->create(['symbol' => $this->asset->symbol, 'unit' => $this->asset->unit]);
        $this->add('2024-01-10', Transaction::factory()->buy('2', '100'));
        $this->add('2024-02-10', Transaction::factory()->transferOut('1.5'));
        $this->add('2024-02-10', Transaction::factory()->transferIn('1.5'), $wallet);
        $sale = $this->add('2024-03-10', Transaction::factory()->sell('1', '300'), $wallet);
        $this->rematch();

        $this->assertSame([['2024-01-10', '1.5', '150.00']], $this->openBefore($sale));
    }
}
