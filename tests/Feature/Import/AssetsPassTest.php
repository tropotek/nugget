<?php

namespace Tests\Feature\Import;

use App\Enums\AssetClass;
use App\Enums\PricedBy;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Passes\AssetsPass;
use App\Import\TkbtcSource;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The assets of a made-up tkbtc database. tkbtc user 2 and exchange 1 have
 * already been imported; exchange 2 (coinspot) was not.
 */
class AssetsPassTest extends TestCase
{
    use RefreshDatabase;
    use TkbtcDatabase;

    private User $user;

    private Exchange $exchange;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTkbtc();
        $this->user = User::factory()->create();
        $this->exchange = Exchange::factory()->for($this->user)->create(['label' => 'BTC Markets']);

        $context = $this->context();
        $context->map('user', 2, $this->user->id);
        $context->map('exchange', 1, $this->exchange->id);
    }

    private function context(): ImportContext
    {
        return new ImportContext(app(TkbtcSource::class));
    }

    private function import(bool $fresh = false): ImportContext
    {
        $context = $this->context();
        (new Importer([app(AssetsPass::class)]))->run($context, $fresh);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function market(int $id, ?int $exchangeId, string $name, string $apiId, string $symbol, string $base, array $attributes = []): void
    {
        $this->tkbtc()->table('market')->insert($attributes + [
            'market_id' => $id, 'user_id' => 2, 'exchange_id' => $exchangeId, 'name' => $name,
            'api_id' => $apiId, 'symbol' => $symbol, 'base' => $base, 'quote' => 'AUD', 'active' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function asset(int $id, int $marketId, array $attributes = []): void
    {
        $this->tkbtc()->table('asset')->insert($attributes + [
            'asset_id' => $id, 'market_id' => $marketId, 'user_id' => 2, 'units' => '1.5', 'in_total' => 1, 'notes' => null,
        ]);
    }

    public function test_a_market_on_an_exchange_becomes_a_crypto_asset_priced_by_that_market(): void
    {
        $this->market(1, 1, 'Bitcoin', 'BTC-AUD', 'BTC/AUD', 'BTC');
        $this->asset(12, 1, ['notes' => "  Main holding\n", 'in_total' => 1]);

        $context = $this->import();

        $asset = Asset::sole();
        $this->assertSame($this->user->id, $asset->user_id);
        $this->assertSame(['Bitcoin', 'BTC', 'BTC', AssetClass::Crypto], [$asset->name, $asset->symbol, $asset->unit, $asset->class]);
        $this->assertSame(PricedBy::Exchange, $asset->pricedBy());
        $this->assertSame([$this->exchange->id, 'BTC-AUD', null], [$asset->exchange_id, $asset->market_id, $asset->feed_symbol]);
        $this->assertTrue($asset->in_total);
        $this->assertFalse($asset->archived);
        $this->assertSame('Main holding', $asset->notes);
        $this->assertSame($asset->id, $context->newId('asset', 12));
        $this->assertSame([], $context->warnings);
    }

    public function test_a_gold_or_silver_feed_becomes_a_metal_in_ounces_priced_by_the_feed(): void
    {
        $this->market(16, null, 'Gold (oz t)', '', 'XAU/AUD', 'XAU');
        $this->market(17, null, 'Silver (oz t)', '', 'XAG/AUD', 'XAG');
        $this->asset(20, 16, ['in_total' => 0]);
        $this->asset(21, 17, ['notes' => '']);

        $this->import();

        $gold = Asset::where('symbol', 'XAU')->sole();
        $this->assertSame(['Gold (oz t)', 'oz', AssetClass::Metal], [$gold->name, $gold->unit, $gold->class]);
        $this->assertSame(PricedBy::Feed, $gold->pricedBy());
        $this->assertSame('XAU/AUD', $gold->feed_symbol);
        $this->assertFalse($gold->in_total);

        $silver = Asset::where('symbol', 'XAG')->sole();
        $this->assertSame(['oz', AssetClass::Metal, 'XAG/AUD', null], [$silver->unit, $silver->class, $silver->feed_symbol, $silver->notes]);
    }

    public function test_any_other_feed_becomes_an_other_asset(): void
    {
        $this->market(18, null, 'US dollars', '', 'USD/AUD', 'usd');
        $this->asset(22, 18);

        $this->import();

        $asset = Asset::sole();
        $this->assertSame(['USD', 'USD', AssetClass::Other, 'USD/AUD'], [$asset->symbol, $asset->unit, $asset->class, $asset->feed_symbol]);
    }

    public function test_a_market_with_no_asset_is_skipped(): void
    {
        $this->market(1, 1, 'Bitcoin', 'BTC-AUD', 'BTC/AUD', 'BTC');
        $this->market(14, 1, 'XRP', 'XRP-AUD', 'XRP/AUD', 'XRP');
        $this->market(11, 1, 'Gold', 'MCAU-AUD', 'MCAU/AUD', 'MCAU', ['active' => 0]);
        $this->asset(12, 1);

        $this->import();

        $this->assertSame(['Bitcoin'], Asset::pluck('name')->all());
    }

    public function test_an_asset_on_an_exchange_that_was_not_imported_comes_over_with_no_price(): void
    {
        $this->market(30, 2, 'Dogecoin', 'DOGE', 'DOGE/AUD', 'DOGE');
        $this->asset(31, 30);

        $context = $this->import();

        $asset = Asset::sole();
        $this->assertSame(['Dogecoin', AssetClass::Crypto, PricedBy::Nothing], [$asset->name, $asset->class, $asset->pricedBy()]);
        $this->assertSame(['Dogecoin (tkbtc asset 31) was imported with no price: its exchange was not imported.'], $context->warnings);
    }

    public function test_an_asset_whose_user_was_not_imported_is_skipped(): void
    {
        $this->market(1, 1, 'Bitcoin', 'BTC-AUD', 'BTC/AUD', 'BTC');
        $this->asset(12, 1, ['user_id' => 99]);

        $context = $this->import();

        $this->assertSame(0, Asset::count());
        $this->assertSame(['Bitcoin (tkbtc asset 12) was not imported: its user was not.'], $context->warnings);
    }

    public function test_two_users_holding_the_same_market_get_an_asset_each(): void
    {
        $second = User::factory()->create();
        $this->context()->map('user', 3, $second->id);
        $this->market(16, null, 'Gold (oz t)', '', 'XAU/AUD', 'XAU');
        $this->asset(20, 16);
        $this->asset(23, 16, ['user_id' => 3]);

        $this->import();

        $this->assertEqualsCanonicalizing([$this->user->id, $second->id], Asset::pluck('user_id')->all());
    }

    public function test_running_again_adds_no_asset_twice(): void
    {
        $this->market(1, 1, 'Bitcoin', 'BTC-AUD', 'BTC/AUD', 'BTC');
        $this->asset(12, 1);
        $this->import();
        Asset::sole()->update(['name' => 'Renamed in Nugget']);

        $this->import();

        $this->assertSame(['Renamed in Nugget'], Asset::pluck('name')->all());
    }

    public function test_a_fresh_run_replaces_the_assets_and_keeps_one_with_transactions_of_its_own(): void
    {
        $this->market(1, 1, 'Bitcoin', 'BTC-AUD', 'BTC/AUD', 'BTC');
        $this->market(16, null, 'Gold (oz t)', '', 'XAU/AUD', 'XAU');
        $this->asset(12, 1);
        $this->asset(20, 16);
        $this->import();
        $bitcoin = Asset::where('symbol', 'BTC')->sole();
        $gold = Asset::where('symbol', 'XAU')->sole();
        $mine = Asset::factory()->for($this->user)->create(['name' => 'Added by hand']);
        // The user has since entered a purchase of gold by hand.
        Transaction::factory()->for($gold)->buy('1', '4000')->create();
        app(ParcelMatcher::class)->rematch($gold);

        $context = $this->import(fresh: true);

        $this->assertModelMissing($bitcoin);
        $this->assertModelExists($gold);
        $this->assertModelExists($mine);
        $this->assertSame(1, Asset::where('symbol', 'XAU')->count());
        $this->assertSame(1, Asset::where('name', 'Bitcoin')->count());
        $this->assertSame($gold->id, $context->newId('asset', 20));
        $this->assertContains('Gold (oz t) has transactions that were not imported, so it was kept.', $context->notes);
    }
}
