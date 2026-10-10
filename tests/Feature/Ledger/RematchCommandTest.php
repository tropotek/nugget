<?php

namespace Tests\Feature\Ledger;

use App\Enums\ParcelMatching;
use App\Facades\Settings;
use App\Ledger\Ledger;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Parcel;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RematchCommandTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::factory()->create(['name' => 'Bitcoin', 'unit' => 'BTC']);
    }

    private function add(string $when, string $state, string ...$amounts): Transaction
    {
        return app(Ledger::class)->record(
            $this->asset,
            Transaction::factory()->{$state}(...$amounts)->raw(['asset_id' => null, 'occurred_at' => $when]),
        );
    }

    /**
     * Throw away what the matcher wrote, as if it had gone wrong.
     */
    private function wipe(): void
    {
        Disposal::query()->delete();
        Parcel::query()->delete();
    }

    public function test_it_rebuilds_one_asset(): void
    {
        $this->add('2024-01-10 02:00:00', 'buy', '1', '100');
        $this->add('2024-06-01 02:00:00', 'sell', '0.4', '200');
        $other = Asset::factory()->create();
        Transaction::factory()->for($other)->create();
        $this->wipe();

        $this->artisan('nugget:rematch', ['--asset' => $this->asset->id])
            ->expectsOutputToContain('Bitcoin')
            ->assertSuccessful();

        $this->assertSame('0.6', (string) $this->asset->parcels()->sole()->units_remaining);
        $this->assertSame(1, Disposal::count());
        $this->assertSame(0, $other->parcels()->count());
    }

    public function test_with_no_options_it_rebuilds_every_asset(): void
    {
        $this->add('2024-01-10 02:00:00', 'buy', '1', '100');
        $other = Asset::factory()->create();
        Transaction::factory()->for($other)->create();
        $this->wipe();

        $this->artisan('nugget:rematch')->assertSuccessful();

        $this->assertSame(1, $this->asset->parcels()->count());
        $this->assertSame(1, $other->parcels()->count());
    }

    public function test_rebuilding_keeps_each_sales_method(): void
    {
        $this->add('2024-01-10 02:00:00', 'buy', '1', '100');
        $this->add('2024-02-10 02:00:00', 'buy', '1', '300');
        $sale = $this->add('2024-06-01 02:00:00', 'sell', '1', '400');
        Settings::set('parcel_matching', 'lowest_gain');

        $this->artisan('nugget:rematch', ['--asset' => $this->asset->id])->assertSuccessful();

        $this->assertSame(ParcelMatching::Fifo, $sale->fresh()->matching);
        $this->assertSame('100.00', $sale->disposals()->sole()->parcel->cost_base->toFixed(2));
    }

    public function test_a_financial_year_is_rematched_by_the_current_setting_and_other_years_are_left(): void
    {
        $this->add('2024-01-10 02:00:00', 'buy', '1', '100');
        $this->add('2024-02-10 02:00:00', 'buy', '1', '300');

        // 12:00 UTC on 30 June is 22:00 that day in Melbourne: the last evening of 2024–25.
        $inYear = $this->add('2025-06-30 12:00:00', 'sell', '0.5', '400');

        // 15:00 UTC on 30 June is 01:00 on 1 July in Melbourne: the first hour of 2025–26.
        $nextYear = $this->add('2025-06-30 15:00:00', 'sell', '0.5', '400');

        Settings::set('parcel_matching', 'lowest_gain');

        $this->artisan('nugget:rematch', ['--year' => 2025])
            ->expectsOutputToContain('2024–25')
            ->assertSuccessful();

        $this->assertSame(ParcelMatching::LowestGain, $inYear->fresh()->matching);
        $this->assertSame('300.00', $inYear->disposals()->sole()->parcel->cost_base->toFixed(2));

        $this->assertSame(ParcelMatching::Fifo, $nextYear->fresh()->matching);
        $this->assertSame('100.00', $nextYear->disposals()->sole()->parcel->cost_base->toFixed(2));
    }

    public function test_a_hand_matched_sale_in_the_year_keeps_its_parcels(): void
    {
        $this->add('2024-01-10 02:00:00', 'buy', '1', '100');
        $this->add('2024-02-10 02:00:00', 'buy', '1', '300');
        $middle = $this->add('2024-03-10 02:00:00', 'buy', '1', '200');
        $sale = $this->add('2025-03-01 02:00:00', 'sell', '1', '400');
        app(Ledger::class)->pick($sale, [['parcel' => $middle->parcels()->sole(), 'units' => '1']]);
        Settings::set('parcel_matching', 'lowest_gain');

        $this->artisan('nugget:rematch', ['--year' => 2025])->assertSuccessful();

        $this->assertSame('200.00', $sale->disposals()->sole()->parcel->cost_base->toFixed(2));
    }

    public function test_a_closed_financial_year_is_refused(): void
    {
        $this->add('2023-01-10 02:00:00', 'buy', '1', '100');
        $sale = $this->add('2024-03-01 02:00:00', 'sell', '1', '400');
        Settings::set('parcel_matching', 'lowest_gain');

        $this->artisan('nugget:rematch', ['--year' => 2024])
            ->expectsOutputToContain('2023–24 is closed')
            ->assertFailed();

        $this->assertSame(ParcelMatching::Fifo, $sale->fresh()->matching);
    }

    public function test_a_year_that_cannot_be_matched_is_reported_and_its_sales_keep_their_method(): void
    {
        $this->add('2024-01-10 02:00:00', 'buy', '1', '100');
        $sale = $this->add('2025-03-01 02:00:00', 'sell', '0.5', '400');
        Transaction::factory()->for($this->asset)->sell('5', '400')->create(['occurred_at' => '2025-04-01 02:00:00']);
        Settings::set('parcel_matching', 'lowest_gain');

        $this->artisan('nugget:rematch', ['--year' => 2025])
            ->expectsOutputToContain('more than the 0.5 BTC held')
            ->assertFailed();

        $this->assertSame(ParcelMatching::Fifo, $sale->fresh()->matching);
        $this->assertSame(1, Disposal::count());
    }

    public function test_an_asset_and_a_year_cannot_be_given_together(): void
    {
        $this->artisan('nugget:rematch', ['--asset' => $this->asset->id, '--year' => 2025])->assertFailed();
    }

    public function test_an_unknown_asset_is_refused(): void
    {
        $this->artisan('nugget:rematch', ['--asset' => 9999])
            ->expectsOutputToContain('no asset')
            ->assertFailed();
    }

    public function test_a_ledger_that_cannot_be_matched_is_reported_and_fails(): void
    {
        Transaction::factory()->for($this->asset)->sell('1', '400')->create(['occurred_at' => '2024-06-01 02:00:00']);

        $this->artisan('nugget:rematch', ['--asset' => $this->asset->id])
            ->expectsOutputToContain('more than the 0 BTC held')
            ->assertFailed();
    }
}
