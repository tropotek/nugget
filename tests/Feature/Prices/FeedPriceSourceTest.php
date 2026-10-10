<?php

namespace Tests\Feature\Prices;

use App\Models\Asset;
use App\Prices\FeedPriceSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The recorded feed response quotes gold at 6002.774 to sell and 6006.027
 * to buy, in AUD for a troy ounce.
 */
class FeedPriceSourceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://forex-data-feed.swissquote.com/public-quotes/bboquotes/instrument/XAU/AUD';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function recorded(): string
    {
        return file_get_contents(base_path('tests/Fixtures/swissquote/xau-aud.json'));
    }

    /**
     * Gold on the XAU/AUD feed, counted in a unit.
     */
    private function gold(string $unit = 'oz'): Asset
    {
        return Asset::factory()->metal()->create(['unit' => $unit]);
    }

    /**
     * @return array{string, string}|null the bid and the ask
     */
    private function quote(Asset $asset): ?array
    {
        $quote = $this->app->make(FeedPriceSource::class)->quote($asset);

        return $quote ? [(string) $quote->bid, (string) $quote->ask] : null;
    }

    public function test_it_quotes_the_first_platforms_first_spread_profile(): void
    {
        Http::fake([self::URL => Http::response($this->recorded())]);

        $this->assertSame(['6002.774', '6006.027'], $this->quote($this->gold()));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL && $request->method() === 'GET');
    }

    public function test_an_ounce_price_is_converted_to_the_assets_unit(): void
    {
        Http::fake([self::URL => Http::response($this->recorded())]);

        // 6002.774 and 6006.027 divided by 31.1034768 grams.
        $this->assertSame(['192.99366558', '193.09825196'], $this->quote($this->gold('g')));
        $this->assertSame(['192993.66558275', '193098.25196134'], $this->quote($this->gold('kg')));
        $this->assertSame(['6002.774', '6006.027'], $this->quote($this->gold('OZ')));
        $this->assertSame(['192.99366558', '193.09825196'], $this->quote($this->gold('G')));
    }

    public function test_a_unit_that_is_not_a_weight_is_quoted_as_the_feed_gives_it(): void
    {
        Http::fake([self::URL => Http::response($this->recorded())]);

        $this->assertSame(['6002.774', '6006.027'], $this->quote($this->gold('coin')));
    }

    public function test_the_feed_url_comes_from_config(): void
    {
        config(['nugget.prices.feed_url' => 'https://feed.test/quotes/']);
        Http::fake(['https://feed.test/quotes/XAG/AUD' => Http::response($this->recorded())]);

        $silver = Asset::factory()->metal()->create(['feed_symbol' => 'XAG/AUD']);

        $this->assertNotNull($this->quote($silver));
    }

    public function test_a_feed_that_cannot_be_reached_gives_no_quote_and_is_logged_not_thrown(): void
    {
        Log::spy();
        Http::fake([self::URL => fn () => throw new ConnectionException('Connection timed out')]);
        $gold = $this->gold();

        $this->assertNull($this->quote($gold));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, "asset {$gold->id} (Gold bullion)")
            && str_contains($message, 'XAU/AUD')
            && str_contains($message, 'Connection timed out'));
    }

    public function test_an_error_from_the_feed_gives_no_quote(): void
    {
        Log::spy();
        Http::fake([self::URL => Http::sequence()->push('Bad gateway', 502)->push('Not found', 404)]);

        $this->assertNull($this->quote($this->gold()));
        $this->assertNull($this->quote($this->gold()));
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_a_symbol_the_feed_does_not_know_gives_no_quote(): void
    {
        Log::spy();
        // What the feed answers for a symbol it does not have.
        Http::fake([self::URL => Http::response('[]')]);

        $this->assertNull($this->quote($this->gold()));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'the feed has no quote for it'));
    }

    public function test_a_response_of_the_wrong_shape_gives_no_quote(): void
    {
        Log::spy();
        $bodies = [
            'not json at all',
            '{"error":"maintenance"}',
            '[{"spreadProfilePrices":[]}]',
            '[{"spreadProfilePrices":[{"spreadProfile":"standard"}]}]',
            '[{"spreadProfilePrices":[{"bid":"n/a","ask":6006.027}]}]',
            '[{"spreadProfilePrices":[{"bid":0,"ask":0}]}]',
        ];
        Http::fake([self::URL => Http::sequence($bodies)]);

        foreach ($bodies as $body) {
            $this->assertNull($this->quote($this->gold()), $body);
        }

        Log::shouldHaveReceived('warning')->times(count($bodies));
    }

    public function test_an_asset_with_no_feed_symbol_gives_no_quote_and_no_request(): void
    {
        Log::spy();
        Http::fake();

        $this->assertNull($this->quote(Asset::factory()->other()->create()));
        Http::assertNothingSent();
    }
}
