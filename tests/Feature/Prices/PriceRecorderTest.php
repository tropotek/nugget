<?php

namespace Tests\Feature\Prices;

use App\Enums\PriceResolution;
use App\Models\Asset;
use App\Models\Price;
use App\Models\User;
use App\Prices\PriceRecorder;
use App\Prices\Quote;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceRecorderTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::factory()->for(User::factory()->create(['timezone' => 'Australia/Melbourne']))->create();
    }

    private function record(string $at, string $bid, string $ask, ?Asset $asset = null): Price
    {
        return app(PriceRecorder::class)->record($asset ?? $this->asset, Quote::of($bid, $ask), CarbonImmutable::parse($at, 'UTC'));
    }

    /**
     * The asset's prices as [resolution, at, bid], oldest first within each resolution.
     *
     * @return list<array{string, string, string}>
     */
    private function prices(?Asset $asset = null): array
    {
        return ($asset ?? $this->asset)->prices()->orderBy('resolution')->orderBy('at')->get()
            ->map(fn (Price $price) => [$price->resolution->value, $price->at->toDateTimeString(), (string) $price->bid])
            ->all();
    }

    public function test_a_quote_is_stored_as_an_intraday_row_and_the_days_row(): void
    {
        $price = $this->record('2025-06-30 03:05:00', '100', '101');

        $this->assertSame(PriceResolution::Intraday, $price->resolution);
        $this->assertSame('101', (string) $price->ask);
        $this->assertSame(
            [['day', '2025-06-30 00:00:00', '100'], ['intraday', '2025-06-30 03:05:00', '100']],
            $this->prices(),
        );
    }

    public function test_a_later_quote_adds_an_intraday_row_and_replaces_the_days_row(): void
    {
        $this->record('2025-06-30 03:05:00', '100', '101');
        $this->record('2025-06-30 03:10:00', '110', '111');

        $this->assertSame(
            [['day', '2025-06-30 00:00:00', '110'], ['intraday', '2025-06-30 03:05:00', '100'], ['intraday', '2025-06-30 03:10:00', '110']],
            $this->prices(),
        );
        $this->assertSame('111', (string) $this->asset->prices()->latestPerAsset()->sole()->ask);
    }

    public function test_the_day_is_the_owners_day(): void
    {
        // 11:55pm on 30 June in Melbourne, then five past midnight.
        $this->record('2025-06-30 13:55:00', '100', '101');
        $this->record('2025-06-30 14:05:00', '110', '111');

        $this->assertSame(
            [
                ['day', '2025-06-30 00:00:00', '100'], ['day', '2025-07-01 00:00:00', '110'],
                ['intraday', '2025-06-30 13:55:00', '100'], ['intraday', '2025-06-30 14:05:00', '110'],
            ],
            $this->prices(),
        );
    }

    public function test_a_second_quote_at_the_same_moment_replaces_the_first(): void
    {
        $this->record('2025-06-30 03:05:00', '100', '101');
        $this->record('2025-06-30 03:05:00', '105', '106');

        $this->assertSame(
            [['day', '2025-06-30 00:00:00', '105'], ['intraday', '2025-06-30 03:05:00', '105']],
            $this->prices(),
        );
    }

    public function test_a_moment_in_another_timezone_is_stored_in_utc(): void
    {
        app(PriceRecorder::class)->record($this->asset, Quote::of('100', '101'), CarbonImmutable::parse('2025-06-30 13:05:00', 'Australia/Melbourne'));

        $this->assertSame([['day', '2025-06-30 00:00:00', '100'], ['intraday', '2025-06-30 03:05:00', '100']], $this->prices());
    }

    public function test_each_asset_has_its_own_prices(): void
    {
        $other = Asset::factory()->for(User::factory()->create(['timezone' => 'America/Los_Angeles']))->create();

        $this->record('2025-06-30 03:05:00', '100', '101');
        $this->record('2025-06-30 03:05:00', '7', '8', $other);

        $this->assertCount(2, $this->prices());
        // 3am UTC is still the evening of the 29th in Los Angeles.
        $this->assertSame([['day', '2025-06-29 00:00:00', '7'], ['intraday', '2025-06-30 03:05:00', '7']], $this->prices($other));
    }
}
