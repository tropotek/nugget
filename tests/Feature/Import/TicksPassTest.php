<?php

namespace Tests\Feature\Import;

use App\Enums\PriceResolution;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Passes\TicksPass;
use App\Import\TkbtcSource;
use App\Models\Asset;
use App\Models\Price;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * tkbtc's ticks reduced to day prices. The made-up tkbtc database has
 * Bitcoin (asset 12, market 1), held by a user in Melbourne, 10 hours ahead
 * of UTC in winter.
 */
class TicksPassTest extends TestCase
{
    use RefreshDatabase;
    use TkbtcDatabase;

    private Asset $bitcoin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTkbtc();
        $this->bitcoin = Asset::factory()->for(User::factory()->create(['timezone' => 'Australia/Melbourne']))->create(['name' => 'Bitcoin']);

        $this->tkbtc()->table('asset')->insert(['asset_id' => 12, 'market_id' => 1, 'user_id' => 2, 'units' => '1']);
        $this->context()->map('asset', 12, $this->bitcoin->id);
    }

    private function context(): ImportContext
    {
        return new ImportContext(app(TkbtcSource::class));
    }

    private function import(): ImportContext
    {
        $context = $this->context();
        $this->imported = (new Importer([app(TicksPass::class)]))->run($context)['Ticks']['imported'];

        return $context;
    }

    private ?int $imported = null;

    private function tick(int $marketId, string $at, string $bid, ?string $ask = null): void
    {
        $this->tkbtc()->table('tick')->insert(['market_id' => $marketId, 'created' => $at, 'bid' => $bid, 'ask' => $ask ?? $bid]);
    }

    /**
     * An asset's day prices as date => "bid ask".
     *
     * @return array<string, string>
     */
    private function days(?Asset $asset = null): array
    {
        return ($asset ?? $this->bitcoin)->prices()->where('resolution', PriceResolution::Day)->orderBy('at')->get()
            ->mapWithKeys(fn (Price $price) => [$price->at->toDateString() => "{$price->bid} {$price->ask}"])
            ->all();
    }

    public function test_each_day_gets_one_price_the_last_tick_of_that_day(): void
    {
        $this->tick(1, '2025-06-10 02:00:00', '100', '101');
        $this->tick(1, '2025-06-10 02:05:00', '110', '111');
        $this->tick(1, '2025-06-10 02:10:00', '120.12345678', '121.5');
        $this->tick(1, '2025-06-11 02:00:00', '200', '201');
        $this->tick(1, '2025-06-13 02:00:00', '400', '401');

        $this->import();

        // A day with no tick has no price.
        $this->assertSame(['2025-06-10' => '120.12345678 121.5', '2025-06-11' => '200 201', '2025-06-13' => '400 401'], $this->days());
        $this->assertSame(3, $this->imported);
        $this->assertSame(0, Price::where('resolution', PriceResolution::Intraday)->count());
    }

    public function test_a_day_is_the_owners_day_not_utcs(): void
    {
        // 23:55 on the 10th in Melbourne, then 00:05 and 23:50 on the 11th.
        $this->tick(1, '2025-06-10 13:55:00', '100');
        $this->tick(1, '2025-06-10 14:05:00', '200');
        $this->tick(1, '2025-06-11 13:50:00', '300');

        $this->import();

        $this->assertSame(['2025-06-10' => '100 100', '2025-06-11' => '300 300'], $this->days());
    }

    public function test_a_price_shows_when_its_tick_was_read(): void
    {
        $this->tick(1, '2025-06-10 02:05:00', '110');

        $this->import();

        $this->assertSame('2025-06-10 02:05:00', $this->bitcoin->prices()->sole()->updated_at->toDateTimeString());
    }

    public function test_the_ticks_are_read_in_slices_and_none_is_lost_between_them(): void
    {
        // Three days of a tick every five minutes: more than one slice would hold if a slice were 500.
        $rows = [];
        $at = strtotime('2025-06-10 00:00:00 UTC');

        for ($i = 0; $i < 864; $i++) {
            $rows[] = ['market_id' => 1, 'created' => gmdate('Y-m-d H:i:s', $at + $i * 300), 'bid' => (string) (1000 + $i), 'ask' => (string) (1001 + $i)];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $this->tkbtc()->table('tick')->insert($chunk);
        }

        $pass = new class extends TicksPass
        {
            public const SLICE = 100;
        };

        (new Importer([$pass]))->run($this->context());

        // The last tick of each Melbourne day: 13:55 UTC.
        $this->assertSame([
            '2025-06-10' => '1167 1168',
            '2025-06-11' => '1455 1456',
            '2025-06-12' => '1743 1744',
            '2025-06-13' => '1863 1864',
        ], $this->days());
    }

    public function test_a_day_that_already_has_a_price_is_left_alone(): void
    {
        Price::factory()->for($this->bitcoin)->day('2025-06-10')->quoted('999', '999')->create();
        $this->tick(1, '2025-06-10 02:00:00', '100');
        $this->tick(1, '2025-06-11 02:00:00', '200');

        $this->import();

        $this->assertSame(['2025-06-10' => '999 999', '2025-06-11' => '200 200'], $this->days());
        $this->assertSame(1, $this->imported);
    }

    public function test_running_again_adds_nothing_twice_and_brings_over_new_days(): void
    {
        $this->tick(1, '2025-06-10 02:00:00', '100');
        $this->import();

        $this->tick(1, '2025-06-10 03:00:00', '150');
        $this->tick(1, '2025-06-11 02:00:00', '200');
        $this->import();

        // The 10th keeps the price it was given first.
        $this->assertSame(['2025-06-10' => '100 100', '2025-06-11' => '200 200'], $this->days());
        $this->assertSame(1, $this->imported);
    }

    public function test_a_markets_ticks_go_to_each_asset_on_it_by_its_owners_calendar(): void
    {
        $perth = Asset::factory()->for(User::factory()->create(['timezone' => 'Australia/Perth']))->create();
        $this->tkbtc()->table('asset')->insert(['asset_id' => 13, 'market_id' => 1, 'user_id' => 3, 'units' => '1']);
        $this->context()->map('asset', 13, $perth->id);
        // 00:30 on the 11th in Melbourne and 22:30 on the 10th in Perth.
        $this->tick(1, '2025-06-10 14:30:00', '100');

        $this->import();

        $this->assertSame(['2025-06-11' => '100 100'], $this->days());
        $this->assertSame(['2025-06-10' => '100 100'], $this->days($perth));
    }

    public function test_the_ticks_of_a_market_nobody_holds_are_not_brought_over(): void
    {
        $this->tick(1, '2025-06-10 02:00:00', '100');
        $this->tick(14, '2025-06-10 02:00:00', '3.5');
        $this->tick(15, '2025-06-10 02:00:00', '4000');

        $context = $this->import();

        $this->assertSame(1, Price::count());
        $this->assertSame(['The ticks of 2 markets with no imported asset were not brought over.'], $context->notes);
    }

    public function test_the_count_is_every_tick_and_a_dry_run_writes_no_price(): void
    {
        $this->tick(1, '2025-06-10 02:00:00', '100');
        $this->tick(1, '2025-06-10 02:05:00', '100');
        $this->tick(14, '2025-06-10 02:00:00', '3.5');

        $report = (new Importer([app(TicksPass::class)]))->run(new ImportContext(app(TkbtcSource::class), dryRun: true));

        $this->assertSame(['Ticks' => ['count' => 3, 'imported' => null]], $report);
        $this->assertSame(0, DB::table('prices')->count());
    }

    public function test_the_latest_price_of_the_asset_is_its_last_imported_day(): void
    {
        $this->tick(1, '2025-06-10 02:00:00', '100');
        $this->tick(1, '2025-06-12 02:00:00', '300', '301');

        $this->import();

        $latest = $this->bitcoin->prices()->latestPerAsset()->sole();
        $this->assertSame(['2025-06-12', '300'], [$latest->at->toDateString(), (string) $latest->bid]);
    }
}
