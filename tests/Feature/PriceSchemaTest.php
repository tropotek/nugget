<?php

namespace Tests\Feature;

use App\Enums\PriceResolution;
use App\Models\Asset;
use App\Models\Price;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class PriceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_prices_table_has_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'asset_id', 'at', 'bid', 'ask', 'resolution', 'created_at', 'updated_at'],
            Schema::getColumnListing('prices'),
        );
    }

    public function test_a_price_belongs_to_an_asset(): void
    {
        $asset = Asset::factory()->create();
        $price = $asset->prices()->create(['at' => '2025-06-30 03:05:00', 'bid' => '100', 'ask' => '101', 'resolution' => 'intraday']);

        $this->assertTrue($price->asset->is($asset));
        $this->assertTrue($asset->prices->first()->is($price));
        $this->assertSame($asset->user_id, $price->ownerId());
    }

    public function test_the_asset_cannot_be_mass_assigned(): void
    {
        $this->assertNull((new Price(['asset_id' => 99, 'bid' => '1']))->asset_id);
    }

    public function test_the_bid_and_ask_are_decimals_stored_to_eight_places(): void
    {
        $price = Price::factory()->quoted('0.00001234', '151300.5')->create();
        $stored = DB::table('prices')->find($price->id);

        $this->assertSame('0.00001234', $stored->bid);
        $this->assertSame('151300.50000000', $stored->ask);
        $this->assertInstanceOf(Decimal::class, $price->fresh()->bid);
        $this->assertSame('151300.5', (string) $price->fresh()->ask);
    }

    public function test_the_time_and_the_resolution_are_cast(): void
    {
        $price = Price::factory()->at('2025-06-30 03:05:00')->create()->fresh();

        $this->assertInstanceOf(Carbon::class, $price->at);
        $this->assertSame('2025-06-30 03:05:00', $price->at->toDateTimeString());
        $this->assertSame(PriceResolution::Intraday, $price->resolution);
        $this->assertSame(PriceResolution::Day, Price::factory()->day('2025-06-30')->create()->fresh()->resolution);
        $this->assertSame(['day', 'intraday'], array_column(PriceResolution::cases(), 'value'));
    }

    public function test_the_mid_price_is_halfway_between_the_bid_and_the_ask(): void
    {
        $this->assertSame('100.5', (string) Price::factory()->quoted('100', '101')->make()->mid());
        $this->assertSame('0.00000002', (string) Price::factory()->quoted('0.00000001', '0.00000002')->make()->mid());
    }

    public function test_a_negative_price_is_refused(): void
    {
        $this->expectException(LogicException::class);

        Price::factory()->quoted('-1', '1')->create();
    }

    // Day rows

    public function test_a_day_row_is_keyed_to_its_date(): void
    {
        $price = Price::factory()->day('2025-06-30')->create()->fresh();

        $this->assertSame('2025-06-30 00:00:00', DB::table('prices')->value('at'));
        $this->assertSame('2025-06-30', $price->at->toDateString());
    }

    public function test_the_day_of_a_moment_is_its_date_in_the_timezone_given(): void
    {
        // 11pm on 30 June in Melbourne, and still 30 June in UTC.
        $moment = CarbonImmutable::parse('2025-06-30 13:00:00', 'UTC');

        $this->assertSame('2025-06-30 00:00:00', Price::day($moment, 'Australia/Melbourne')->toDateTimeString());
        $this->assertSame('2025-07-01 00:00:00', Price::day($moment->addHours(2), 'Australia/Melbourne')->toDateTimeString());
        $this->assertSame('2025-06-30 00:00:00', Price::day($moment->addHours(2), 'UTC')->toDateTimeString());
        // 3am UTC on 30 June is still the evening of the 29th in Los Angeles.
        $this->assertSame('2025-06-29 00:00:00', Price::day($moment->subHours(10), 'America/Los_Angeles')->toDateTimeString());

        // With no timezone, the date is the moment's own.
        $this->assertSame('2025-07-01 00:00:00', Price::day($moment->addHours(2)->setTimezone('Australia/Melbourne'))->toDateTimeString());
        $this->assertSame('2025-06-30 00:00:00', Price::day('2025-06-30')->toDateTimeString());
        $this->assertSame('UTC', Price::day('2025-06-30')->timezoneName);
    }

    public function test_a_day_row_with_a_time_of_day_is_refused(): void
    {
        $this->expectException(LogicException::class);

        Price::factory()->create(['at' => '2025-06-30 03:05:00', 'resolution' => PriceResolution::Day]);
    }

    // The unique index

    public function test_an_asset_has_one_price_for_a_resolution_and_a_time(): void
    {
        $asset = Asset::factory()->create();
        Price::factory()->for($asset)->day('2025-06-30')->create();

        $this->expectException(QueryException::class);

        Price::factory()->for($asset)->day('2025-06-30')->create();
    }

    public function test_the_same_time_can_have_a_day_row_and_an_intraday_row_and_a_row_for_each_asset(): void
    {
        $asset = Asset::factory()->create();
        Price::factory()->for($asset)->day('2025-06-30')->create();
        Price::factory()->for($asset)->at('2025-06-30 00:00:00')->create();
        Price::factory()->day('2025-06-30')->create();

        $this->assertSame(3, Price::count());
    }

    // Scopes

    public function test_the_latest_price_of_each_asset_is_its_newest_day_row(): void
    {
        $bitcoin = Asset::factory()->create();
        $gold = Asset::factory()->metal()->create();
        Asset::factory()->other()->create();

        Price::factory()->for($bitcoin)->day('2025-06-29')->quoted('100', '101')->create();
        Price::factory()->for($bitcoin)->day('2025-06-30')->quoted('110', '111')->create();
        Price::factory()->for($bitcoin)->at('2025-06-30 05:00:00')->quoted('105', '106')->create();
        Price::factory()->for($gold)->day('2025-06-01')->quoted('5000', '5010')->create();
        // Newer than the gold day row, and still not the latest: intraday rows are pruned.
        Price::factory()->for($gold)->at('2025-06-02 05:00:00')->quoted('5100', '5110')->create();

        $latest = Price::latestPerAsset()->get()->keyBy('asset_id');

        $this->assertSame([$bitcoin->id, $gold->id], $latest->keys()->sort()->values()->all());
        $this->assertSame('110', (string) $latest[$bitcoin->id]->bid);
        $this->assertSame('5000', (string) $latest[$gold->id]->bid);
        $this->assertSame('110', (string) $bitcoin->prices()->latestPerAsset()->sole()->bid);
    }

    public function test_the_daily_range_includes_both_ends_oldest_first(): void
    {
        $asset = Asset::factory()->create();

        foreach (['2025-07-02', '2025-06-29', '2025-07-01', '2025-06-30'] as $date) {
            Price::factory()->for($asset)->day($date)->create();
        }
        Price::factory()->for($asset)->at('2025-06-30 12:00:00')->create();

        $days = fn ($from, $to) => $asset->prices()->daily($from, $to)->get()->map(fn (Price $p) => $p->at->toDateString())->all();

        $this->assertSame(['2025-06-30', '2025-07-01'], $days('2025-06-30', '2025-07-01'));
        $this->assertSame(['2025-06-29', '2025-06-30', '2025-07-01', '2025-07-02'], $days('2025-01-01', '2025-12-31'));
        $this->assertSame([], $days('2025-07-03', '2025-07-31'));

        // A moment counts as its own date: 1 July in Melbourne here, though 30 June in UTC.
        $moment = CarbonImmutable::parse('2025-07-01 08:00:00', 'Australia/Melbourne');
        $this->assertSame(['2025-07-01', '2025-07-02'], $days($moment, $moment->addDay()));
    }

    public function test_the_intraday_range_includes_both_ends_oldest_first(): void
    {
        $asset = Asset::factory()->create();

        foreach (['2025-06-30 03:10:00', '2025-06-30 03:00:00', '2025-06-30 03:05:00', '2025-06-30 03:15:00'] as $at) {
            Price::factory()->for($asset)->at($at)->create();
        }
        Price::factory()->for($asset)->day('2025-06-30')->create();

        $times = fn (string $from, string $to, string $timezone = 'UTC') => $asset->prices()
            ->intraday(CarbonImmutable::parse($from, $timezone), CarbonImmutable::parse($to, $timezone))
            ->get()->map(fn (Price $p) => $p->at->format('H:i'))->all();

        $this->assertSame(['03:05', '03:10'], $times('2025-06-30 03:05:00', '2025-06-30 03:10:00'));
        $this->assertSame(['03:00', '03:05', '03:10', '03:15'], $times('2025-06-29 00:00:00', '2025-07-01 00:00:00'));
        $this->assertSame(['03:05', '03:10'], $times('2025-06-30 13:05:00', '2025-06-30 13:10:00', 'Australia/Melbourne'));
    }

    // Ownership and deletes

    public function test_only_the_owner_of_the_asset_may_see_a_price(): void
    {
        $price = Price::factory()->create();

        $this->assertTrue(Gate::forUser($price->asset->user)->allows('view', $price));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('view', $price));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->create())->allows('view', $price));
    }

    public function test_deleting_an_asset_deletes_its_prices(): void
    {
        $asset = Asset::factory()->create();
        Price::factory()->for($asset)->day('2025-06-30')->create();
        Price::factory()->for($asset)->create();
        $other = Price::factory()->create();

        $asset->delete();

        $this->assertSame([$other->id], Price::pluck('id')->all());
    }

    public function test_the_factory_makes_an_intraday_price_now(): void
    {
        $this->travelTo('2025-06-30 03:05:07');

        $price = Price::factory()->create()->fresh();

        $this->assertSame(PriceResolution::Intraday, $price->resolution);
        $this->assertSame('2025-06-30 03:05:07', $price->at->toDateTimeString());
        $this->assertTrue($price->ask->greaterThan($price->bid));
        $this->assertNotNull($price->asset);
    }
}
