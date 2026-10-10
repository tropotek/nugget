<?php

namespace Tests\Feature\Prices;

use App\Enums\PriceResolution;
use App\Models\Asset;
use App\Models\Price;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Now" is 03:20 UTC on 30 June 2025, so the cut-off is 03:20 on the 28th.
 */
class PrunePricesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-06-30 03:20:00');
        $this->asset = Asset::factory()->create();
    }

    /**
     * @return list<string> the times of the intraday rows left, oldest first
     */
    private function intraday(): array
    {
        return Price::where('resolution', PriceResolution::Intraday)->orderBy('at')->get()
            ->map(fn (Price $price) => $price->at->toDateTimeString())->all();
    }

    public function test_it_deletes_the_intraday_prices_past_the_cut_off(): void
    {
        foreach (['2025-06-20 12:00:00', '2025-06-28 03:15:00', '2025-06-28 03:20:00', '2025-06-28 03:25:00', '2025-06-30 03:15:00'] as $at) {
            Price::factory()->for($this->asset)->at($at)->create();
        }

        $this->artisan('nugget:prune-prices')
            ->expectsOutputToContain('Deleted 2 intraday prices older than 48 hours.')
            ->assertSuccessful();

        // The row exactly 48 hours old is kept.
        $this->assertSame(['2025-06-28 03:20:00', '2025-06-28 03:25:00', '2025-06-30 03:15:00'], $this->intraday());
    }

    public function test_day_prices_are_never_touched(): void
    {
        foreach (['2020-01-01', '2025-06-01', '2025-06-27', '2025-06-28', '2025-06-30'] as $date) {
            Price::factory()->for($this->asset)->day($date)->create();
        }
        Price::factory()->for($this->asset)->at('2020-01-01 00:00:00')->create();

        $this->artisan('nugget:prune-prices')->expectsOutputToContain('Deleted 1 intraday prices')->assertSuccessful();

        $this->assertSame(5, Price::where('resolution', PriceResolution::Day)->count());
        $this->assertSame([], $this->intraday());
    }

    public function test_day_prices_survive_any_setting(): void
    {
        Price::factory()->for($this->asset)->day('2025-06-30')->create();
        Price::factory()->for($this->asset)->at('2025-06-30 03:19:00')->create();

        foreach ([0, -5] as $hours) {
            config(['nugget.prices.intraday_hours' => $hours]);
            $this->artisan('nugget:prune-prices')->assertSuccessful();
        }

        $this->assertSame(1, Price::where('resolution', PriceResolution::Day)->count());
        $this->assertSame([], $this->intraday());
    }

    public function test_it_prunes_every_assets_prices(): void
    {
        Price::factory()->for($this->asset)->at('2025-06-01 00:00:00')->create();
        Price::factory()->at('2025-06-01 00:00:00')->create();
        Price::factory()->at('2025-06-30 03:00:00')->create();

        $this->artisan('nugget:prune-prices')->assertSuccessful();

        $this->assertSame(['2025-06-30 03:00:00'], $this->intraday());
    }

    public function test_the_hours_kept_come_from_config(): void
    {
        config(['nugget.prices.intraday_hours' => 1]);
        Price::factory()->for($this->asset)->at('2025-06-30 02:00:00')->create();
        Price::factory()->for($this->asset)->at('2025-06-30 02:30:00')->create();

        $this->artisan('nugget:prune-prices')->expectsOutputToContain('Deleted 1 intraday prices older than 1 hours.')->assertSuccessful();

        $this->assertSame(['2025-06-30 02:30:00'], $this->intraday());
    }

    public function test_with_nothing_to_delete_it_succeeds(): void
    {
        $this->artisan('nugget:prune-prices')->expectsOutputToContain('Deleted 0 intraday prices')->assertSuccessful();
    }

    public function test_it_is_scheduled_nightly_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->sole(fn ($event) => str_contains($event->command, 'nugget:prune-prices'));

        $this->assertSame('20 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
