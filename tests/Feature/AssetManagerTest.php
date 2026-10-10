<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\PricedBy;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssetManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'name' => 'Gold bullion',
            'symbol' => 'XAU',
            'class' => 'metal',
            'unit' => 'oz',
            'priced_by' => 'none',
            'in_total' => '1',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function asset(array $attributes = [], ?User $user = null): Asset
    {
        return Asset::factory()->for($user ?? $this->user)->create($attributes);
    }

    /**
     * Give an asset a buy of 3 units for $300 and a sale of 1.
     */
    private function trade(Asset $asset): void
    {
        Transaction::factory()->for($asset)->buy('3', '100')->create(['occurred_at' => '2024-01-10 02:00:00']);
        Transaction::factory()->for($asset)->sell('1', '150')->create(['occurred_at' => '2024-03-10 02:00:00']);
        app(ParcelMatcher::class)->rematch($asset);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/assets')->assertRedirect(route('login'));
        $this->get('/assets/create')->assertRedirect(route('login'));
    }

    public function test_the_sidebar_shows_assets(): void
    {
        $this->actingAs($this->user)->get('/')->assertSee('href="'.route('assets.index').'"', false);
    }

    // The list

    public function test_the_list_shows_each_asset_with_its_units_and_cost_base(): void
    {
        $this->trade($this->asset(['name' => 'Bitcoin', 'symbol' => 'BTC', 'unit' => 'BTC']));
        $this->asset(['name' => 'Silver bullion', 'symbol' => 'XAG', 'class' => AssetClass::Metal, 'unit' => 'oz', 'feed_symbol' => 'XAG/AUD']);

        $this->actingAs($this->user)
            ->get('/assets')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Assets</h4>', false)
            ->assertSeeInOrder(['Bitcoin', 'BTC', 'Crypto', '2 BTC', '$200.00', 'Silver bullion', 'XAG', 'Metal', '0 oz', '$0.00', 'XAG/AUD'])
            ->assertSee('href="'.route('assets.create').'"', false);
    }

    public function test_the_list_names_the_exchange_market_that_prices_an_asset(): void
    {
        $exchange = Exchange::factory()->for($this->user)->create(['label' => 'BTC Markets']);
        Asset::factory()->onExchange($exchange, 'BTC-AUD')->create();

        $this->actingAs($this->user)->get('/assets')->assertSeeInOrder(['BTC Markets', 'BTC-AUD']);
    }

    public function test_the_list_marks_an_estimated_cost_and_an_asset_left_out_of_the_total(): void
    {
        $asset = $this->asset(['in_total' => false]);
        Transaction::factory()->for($asset)->buy('1', '100')->estimated()->create();
        app(ParcelMatcher::class)->rematch($asset);

        $this->actingAs($this->user)->get('/assets')->assertSee('Estimated')->assertSee('Not in total');
    }

    public function test_the_list_leaves_out_other_users_assets(): void
    {
        $this->asset(['name' => 'Mine']);
        $this->asset(['name' => 'Theirs'], User::factory()->create());

        $this->actingAs($this->user)->get('/assets')->assertSee('Mine')->assertDontSee('Theirs');
    }

    public function test_the_list_is_empty_for_a_user_with_no_assets(): void
    {
        $this->actingAs($this->user)->get('/assets')->assertOk()->assertSee('No assets yet.');
    }

    public function test_archived_assets_are_hidden_until_asked_for(): void
    {
        $this->asset(['name' => 'Bitcoin']);
        $this->asset(['name' => 'Litecoin', 'archived' => true]);

        $this->actingAs($this->user)->get('/assets')
            ->assertSee('Bitcoin')
            ->assertDontSee('Litecoin')
            ->assertSee('href="'.route('assets.index', ['archived' => 1]).'"', false);

        $this->actingAs($this->user)->get('/assets?archived=1')
            ->assertSee('Bitcoin')
            ->assertSee('Litecoin')
            ->assertSee('Archived')
            ->assertSee('href="'.route('assets.index').'"', false);
    }

    public function test_there_is_no_link_to_archived_assets_when_there_are_none(): void
    {
        $this->asset();

        $this->actingAs($this->user)->get('/assets')->assertDontSee('archived=1', false);
    }

    // Adding

    public function test_the_add_form_offers_the_users_own_exchanges(): void
    {
        Exchange::factory()->for($this->user)->create(['label' => 'My exchange']);
        Exchange::factory()->create(['label' => 'Their exchange']);

        $this->actingAs($this->user)->get('/assets/create')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Add asset</h4>', false)
            ->assertSee('My exchange')
            ->assertDontSee('Their exchange')
            ->assertDontSee('name="archived"', false);
    }

    public function test_an_asset_with_no_price_is_added(): void
    {
        $this->actingAs($this->user)
            ->post('/assets', $this->form(['symbol' => ' xau ', 'notes' => 'In the safe.', 'in_total' => '0']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('assets.index'))
            ->assertSessionHas('success');

        $asset = $this->user->assets()->sole();
        $this->assertSame('Gold bullion', $asset->name);
        $this->assertSame('XAU', $asset->symbol);
        $this->assertSame(AssetClass::Metal, $asset->class);
        $this->assertSame('oz', $asset->unit);
        $this->assertSame(PricedBy::Nothing, $asset->pricedBy());
        $this->assertFalse($asset->in_total);
        $this->assertSame('In the safe.', $asset->notes);
        $this->assertFalse($asset->archived);
    }

    public function test_an_asset_priced_by_an_exchange_market_is_added(): void
    {
        $exchange = Exchange::factory()->for($this->user)->create();

        $this->actingAs($this->user)
            ->post('/assets', $this->form([
                'priced_by' => 'exchange', 'exchange_id' => $exchange->id, 'market_id' => 'BTC-AUD', 'feed_symbol' => 'XAU/AUD',
            ]))
            ->assertSessionHasNoErrors();

        $asset = $this->user->assets()->sole();
        $this->assertSame(PricedBy::Exchange, $asset->pricedBy());
        $this->assertTrue($asset->exchange->is($exchange));
        $this->assertSame('BTC-AUD', $asset->market_id);
        $this->assertNull($asset->feed_symbol);
    }

    public function test_an_asset_priced_by_the_feed_is_added(): void
    {
        $exchange = Exchange::factory()->for($this->user)->create();

        $this->actingAs($this->user)
            ->post('/assets', $this->form([
                'priced_by' => 'feed', 'feed_symbol' => 'XAU/AUD', 'exchange_id' => $exchange->id, 'market_id' => 'BTC-AUD',
            ]))
            ->assertSessionHasNoErrors();

        $asset = $this->user->assets()->sole();
        $this->assertSame(PricedBy::Feed, $asset->pricedBy());
        $this->assertSame('XAU/AUD', $asset->feed_symbol);
        $this->assertNull($asset->exchange_id);
        $this->assertNull($asset->market_id);
    }

    public function test_a_new_asset_cannot_be_archived_or_given_to_someone_else(): void
    {
        $other = User::factory()->create();

        $this->actingAs($this->user)
            ->post('/assets', $this->form(['archived' => '1', 'user_id' => $other->id]))
            ->assertSessionHasNoErrors();

        $asset = Asset::sole();
        $this->assertFalse($asset->archived);
        $this->assertSame($this->user->id, $asset->user_id);
    }

    public function test_the_required_fields_are_checked(): void
    {
        $this->actingAs($this->user)
            ->post('/assets', ['class' => 'shares', 'priced_by' => 'guess'])
            ->assertSessionHasErrors(['name', 'symbol', 'class', 'unit', 'priced_by']);

        $this->assertSame(0, Asset::count());
    }

    public function test_an_exchange_price_needs_an_exchange_and_a_market(): void
    {
        $this->actingAs($this->user)
            ->post('/assets', $this->form(['priced_by' => 'exchange']))
            ->assertSessionHasErrors(['exchange_id', 'market_id'])
            ->assertSessionDoesntHaveErrors(['feed_symbol']);

        $this->assertSame(0, Asset::count());
    }

    public function test_another_users_exchange_is_refused(): void
    {
        $theirs = Exchange::factory()->create();

        $this->actingAs($this->user)
            ->post('/assets', $this->form(['priced_by' => 'exchange', 'exchange_id' => $theirs->id, 'market_id' => 'BTC-AUD']))
            ->assertSessionHasErrors(['exchange_id']);

        $this->assertSame(0, Asset::count());
    }

    public function test_a_feed_price_needs_a_feed_symbol(): void
    {
        $this->actingAs($this->user)
            ->post('/assets', $this->form(['priced_by' => 'feed']))
            ->assertSessionHasErrors(['feed_symbol'])
            ->assertSessionDoesntHaveErrors(['exchange_id', 'market_id']);
    }

    // Editing

    public function test_another_users_asset_cannot_be_reached(): void
    {
        $theirs = $this->asset(['name' => 'Theirs'], User::factory()->create());
        $this->actingAs($this->user);

        $this->get("/assets/{$theirs->id}/edit")->assertForbidden();
        $this->put("/assets/{$theirs->id}", $this->form())->assertForbidden();
        $this->delete("/assets/{$theirs->id}")->assertForbidden();

        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_an_admin_cannot_reach_another_users_asset(): void
    {
        $asset = $this->asset();

        $this->actingAs(User::factory()->admin()->create())->get("/assets/{$asset->id}/edit")->assertForbidden();
    }

    public function test_the_edit_form_shows_the_asset(): void
    {
        $asset = $this->asset(['name' => 'Silver bullion', 'symbol' => 'XAG', 'class' => AssetClass::Metal, 'unit' => 'oz', 'feed_symbol' => 'XAG/AUD']);

        $this->actingAs($this->user)->get("/assets/{$asset->id}/edit")
            ->assertOk()
            ->assertSee('<h4 class="page-title">Edit asset</h4>', false)
            ->assertSee('value="Silver bullion"', false)
            ->assertSee('value="XAG/AUD"', false)
            ->assertSee('<option value="feed" selected>', false)
            ->assertSee('name="archived"', false)
            ->assertSee('Delete asset');
    }

    public function test_an_asset_is_saved(): void
    {
        $exchange = Exchange::factory()->for($this->user)->create();
        $asset = Asset::factory()->onExchange($exchange, 'BTC-AUD')->create();

        $this->actingAs($this->user)
            ->put("/assets/{$asset->id}", $this->form(['name' => 'Gold bars', 'priced_by' => 'feed', 'feed_symbol' => 'XAU/AUD', 'notes' => '']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('assets.index'))
            ->assertSessionHas('success');

        $asset->refresh();
        $this->assertSame('Gold bars', $asset->name);
        $this->assertSame('XAU', $asset->symbol);
        $this->assertSame('oz', $asset->unit);
        $this->assertSame(AssetClass::Metal, $asset->class);
        $this->assertSame(PricedBy::Feed, $asset->pricedBy());
        $this->assertNull($asset->exchange_id);
        $this->assertNull($asset->market_id);
        $this->assertNull($asset->notes);
    }

    public function test_an_asset_can_be_archived_and_brought_back(): void
    {
        $asset = $this->asset();
        $this->trade($asset);
        $this->actingAs($this->user);

        $this->put("/assets/{$asset->id}", $this->form(['archived' => '1']))->assertSessionHasNoErrors();
        $this->assertTrue($asset->fresh()->archived);
        $this->assertSame(1, $asset->parcels()->count());

        $this->put("/assets/{$asset->id}", $this->form(['archived' => '0']))->assertSessionHasNoErrors();
        $this->assertFalse($asset->fresh()->archived);
    }

    public function test_the_symbol_and_unit_are_fixed_once_there_are_transactions(): void
    {
        $asset = $this->asset(['symbol' => 'BTC', 'unit' => 'BTC']);
        $this->trade($asset);
        $this->actingAs($this->user);

        $this->get("/assets/{$asset->id}/edit")
            ->assertSee('name="symbol" value="BTC" disabled', false)
            ->assertSee('name="unit" value="BTC" disabled', false);

        $this->put("/assets/{$asset->id}", $this->form(['name' => 'Renamed', 'symbol' => 'ETH', 'unit' => 'ETH']))
            ->assertSessionHasNoErrors();
        $this->put("/assets/{$asset->id}", ['name' => 'Renamed again', 'class' => 'crypto', 'priced_by' => 'none'])
            ->assertSessionHasNoErrors();

        $asset->refresh();
        $this->assertSame('Renamed again', $asset->name);
        $this->assertSame('BTC', $asset->symbol);
        $this->assertSame('BTC', $asset->unit);
    }

    // Deleting

    public function test_an_asset_with_no_transactions_is_deleted(): void
    {
        $asset = $this->asset(['name' => 'Typo']);

        $this->actingAs($this->user)
            ->delete("/assets/{$asset->id}")
            ->assertRedirect(route('assets.index'))
            ->assertSessionHas('success');

        $this->assertNull($asset->fresh());
    }

    public function test_an_asset_with_transactions_is_not_deleted(): void
    {
        $asset = $this->asset();
        $this->trade($asset);
        $this->actingAs($this->user);

        $this->get("/assets/{$asset->id}/edit")->assertDontSee('Delete asset');

        $this->delete("/assets/{$asset->id}")
            ->assertRedirect(route('assets.edit', $asset))
            ->assertSessionHas('error');

        $this->assertNotNull($asset->fresh());
        $this->assertSame(2, $asset->transactions()->count());
    }

    // The exchange's markets

    private function fakeTheMarkets(): void
    {
        Http::fake(['https://api.btcmarkets.net/v3/markets' => Http::response(file_get_contents(base_path('tests/Fixtures/btcmarkets/markets.json')))]);
    }

    public function test_the_form_offers_the_aud_markets_of_the_users_exchanges(): void
    {
        $this->fakeTheMarkets();
        $exchange = Exchange::factory()->for($this->user)->create();
        Exchange::factory()->for($this->user)->create(['label' => 'Second account']);
        $asset = Asset::factory()->onExchange($exchange, 'BTC-AUD')->create();

        $this->actingAs($this->user)->get(route('assets.edit', $asset))
            ->assertOk()
            ->assertSee('<datalist id="markets-btcmarkets">', false)
            ->assertSee('<option value="BTC-AUD"></option>', false)
            ->assertDontSee('<option value="ETH-BTC"></option>', false)
            ->assertSee('list="markets-btcmarkets"', false)
            ->assertSee('data-markets="markets-btcmarkets"', false)
            ->assertViewHas('markets', fn (array $markets) => array_keys($markets) === ['btcmarkets'] && count($markets['btcmarkets']) === 44);

        Http::assertSentCount(1);
    }

    public function test_the_form_works_when_the_markets_cannot_be_read(): void
    {
        Http::fake(['*' => Http::response('Service unavailable', 503)]);
        Exchange::factory()->for($this->user)->create();

        $this->actingAs($this->user)->get('/assets/create')
            ->assertOk()
            ->assertSee('name="market_id"', false)
            ->assertDontSee('<datalist', false);
    }

    public function test_a_market_the_exchange_does_not_price_in_aud_is_refused(): void
    {
        $this->fakeTheMarkets();
        $exchange = Exchange::factory()->for($this->user)->create(['label' => 'My account']);

        foreach (['BTC/AUD', 'ETH-BTC', 'NOPE-AUD'] as $market) {
            $this->actingAs($this->user)
                ->post('/assets', $this->form(['priced_by' => 'exchange', 'exchange_id' => $exchange->id, 'market_id' => $market]))
                ->assertSessionHasErrors(['market_id' => "My account has no market called {$market} that is priced in AUD."]);
        }

        $this->assertSame(0, $this->user->assets()->count());

        $this->actingAs($this->user)
            ->post('/assets', $this->form(['priced_by' => 'exchange', 'exchange_id' => $exchange->id, 'market_id' => 'BTC-AUD']))
            ->assertSessionHasNoErrors();
    }

    public function test_any_market_is_taken_when_the_markets_cannot_be_read(): void
    {
        Http::fake(['*' => Http::response('Service unavailable', 503)]);
        $exchange = Exchange::factory()->for($this->user)->create();

        $this->actingAs($this->user)
            ->post('/assets', $this->form(['priced_by' => 'exchange', 'exchange_id' => $exchange->id, 'market_id' => 'NEW-AUD']))
            ->assertSessionHasNoErrors();

        $this->assertSame('NEW-AUD', $this->user->assets()->sole()->market_id);
    }

    public function test_an_asset_keeps_a_market_the_exchange_no_longer_lists(): void
    {
        $this->fakeTheMarkets();
        $exchange = Exchange::factory()->for($this->user)->create();
        $asset = Asset::factory()->onExchange($exchange, 'GONE-AUD')->create(['name' => 'Old coin']);
        $change = fn (array $changes) => $this->form(['priced_by' => 'exchange', 'exchange_id' => $exchange->id, ...$changes]);

        $this->actingAs($this->user)
            ->put(route('assets.update', $asset), $change(['name' => 'Older coin', 'market_id' => 'GONE-AUD']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Older coin', $asset->refresh()->name);

        $this->actingAs($this->user)
            ->put(route('assets.update', $asset), $change(['market_id' => 'WENT-AUD']))
            ->assertSessionHasErrors('market_id');
    }
}
