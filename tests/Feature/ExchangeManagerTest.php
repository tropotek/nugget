<?php

namespace Tests\Feature;

use App\Exchanges\ExchangeDrivers;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\ExchangeBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\PlainDriver;
use Tests\TestCase;

class ExchangeManagerTest extends TestCase
{
    use RefreshDatabase;

    private const BALANCES = 'https://api.btcmarkets.net/v3/accounts/me/balances';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'driver' => 'btcmarkets',
            'label' => 'My BTC Markets',
            'api_key' => 'the-api-key',
            'secret' => base64_encode('the-secret'),
            'active' => '1',
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function exchange(array $attributes = [], ?User $user = null): Exchange
    {
        return Exchange::factory()->for($user ?? $this->user)->create($attributes);
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $exchange = $this->exchange();
        auth()->logout();

        $this->get('/exchanges')->assertRedirect(route('login'));
        $this->get('/exchanges/create')->assertRedirect(route('login'));
        $this->post("/exchanges/{$exchange->id}/test")->assertRedirect(route('login'));
    }

    public function test_the_sidebar_shows_exchanges(): void
    {
        $this->get('/')->assertSee('href="'.route('exchanges.index').'"', false);
    }

    // The list

    public function test_the_list_shows_each_exchange_with_its_last_sync_and_error(): void
    {
        $synced = $this->exchange(['label' => 'Main account']);
        $synced->forceFill(['aud_balance' => '1520.45', 'last_synced_at' => '2025-06-30 02:15:00'])->save();
        $failed = $this->exchange(['label' => 'Old account', 'active' => false]);
        $failed->forceFill(['last_error' => 'invalid api key (InvalidAPIKey, HTTP 401)'])->save();
        Asset::factory()->onExchange($synced, 'BTC-AUD')->count(2)->create();

        $this->get('/exchanges')
            ->assertOk()
            ->assertSeeInOrder([
                'Main account', 'BTC Markets', '$1,520.45', '2', '30 Jun 2025, 12:15pm',
                'Old account', 'BTC Markets', '$0.00', '0', 'Never', 'invalid api key (InvalidAPIKey, HTTP 401)', 'Inactive',
            ])
            ->assertSee('href="'.route('exchanges.edit', $synced).'"', false)
            ->assertSee('action="'.route('exchanges.test', $synced).'"', false)
            ->assertSee('href="'.route('exchanges.create').'"', false);
    }

    public function test_the_list_has_only_the_users_own_exchanges(): void
    {
        $this->exchange(['label' => 'Mine']);
        $this->exchange(['label' => 'Theirs'], User::factory()->create());

        $this->get('/exchanges')->assertSee('Mine')->assertDontSee('Theirs');
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->get('/exchanges')->assertOk()->assertSee('No exchanges yet.');
    }

    public function test_the_list_never_holds_a_key_or_a_secret(): void
    {
        $this->exchange(['api_key' => 'KEY-ABCDEF', 'secret' => 'SECRET-UVWXYZ']);

        $this->get('/exchanges')->assertDontSee('KEY-ABCDEF')->assertDontSee('SECRET-UVWXYZ');
    }

    // Adding

    public function test_the_add_form_offers_the_drivers(): void
    {
        $this->get('/exchanges/create')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Add exchange</h4>', false)
            ->assertSee('<option value="btcmarkets" selected>BTC Markets</option>', false)
            ->assertDontSee('Test connection')
            ->assertDontSee('Delete exchange');
    }

    public function test_an_exchange_is_added_with_its_key_and_secret_encrypted(): void
    {
        $response = $this->post('/exchanges', $this->form())->assertSessionHasNoErrors()->assertSessionHas('success');

        $exchange = $this->user->exchanges()->sole();
        $response->assertRedirect(route('exchanges.edit', $exchange));

        $this->assertSame('btcmarkets', $exchange->driver);
        $this->assertSame('My BTC Markets', $exchange->label);
        $this->assertSame('the-api-key', $exchange->api_key);
        $this->assertSame(base64_encode('the-secret'), $exchange->secret);
        $this->assertTrue($exchange->active);

        $stored = DB::table('exchanges')->first();
        $this->assertStringNotContainsString('the-api-key', $stored->api_key);
        $this->assertStringNotContainsString(base64_encode('the-secret'), $stored->secret);
    }

    public function test_a_new_exchange_needs_a_driver_a_label_a_key_and_a_secret(): void
    {
        $this->post('/exchanges', ['active' => '1'])->assertSessionHasErrors(['driver', 'label', 'api_key', 'secret']);
        $this->post('/exchanges', $this->form(['driver' => 'kraken']))->assertSessionHasErrors('driver');

        $this->assertSame(0, Exchange::count());
    }

    public function test_a_refused_form_does_not_keep_or_send_back_the_key_and_secret(): void
    {
        $refused = $this->form(['label' => '', 'api_key' => 'KEY-ABCDEF', 'secret' => 'SECRET-UVWXYZ']);

        $this->from('/exchanges/create')->post('/exchanges', $refused)
            ->assertRedirect('/exchanges/create')
            ->assertSessionHasErrors('label')
            ->assertSessionHasInput('driver', 'btcmarkets')
            ->assertSessionMissing('_old_input.api_key')
            ->assertSessionMissing('_old_input.secret');

        $this->followingRedirects()->from('/exchanges/create')->post('/exchanges', $refused)
            ->assertSee('The label field is required.')
            ->assertDontSee('KEY-ABCDEF')
            ->assertDontSee('SECRET-UVWXYZ');
    }

    public function test_the_sync_results_cannot_be_set_from_the_form(): void
    {
        $this->post('/exchanges', $this->form(['aud_balance' => '999.00', 'last_error' => 'made up', 'user_id' => User::factory()->create()->id]));

        $exchange = Exchange::sole();
        $this->assertSame($this->user->id, $exchange->user_id);
        $this->assertSame('0.00', $exchange->aud_balance->toFixed(2));
        $this->assertNull($exchange->last_error);
    }

    // Editing

    public function test_the_edit_form_never_shows_the_saved_key_or_secret(): void
    {
        $exchange = $this->exchange(['label' => 'Main account', 'api_key' => 'KEY-ABCDEF', 'secret' => 'SECRET-UVWXYZ']);

        $this->get(route('exchanges.edit', $exchange))
            ->assertOk()
            ->assertSee('<h4 class="page-title">Edit exchange</h4>', false)
            ->assertSee('value="Main account"', false)
            ->assertSee('A key is saved. Leave this blank to keep it.')
            ->assertSee('A secret is saved and is not shown again.')
            ->assertSee('The exchange of an account cannot be changed.')
            ->assertDontSee('KEY-ABCDEF')
            ->assertDontSee('SECRET-UVWXYZ')
            ->assertDontSee('CDEF');
    }

    public function test_the_edit_page_says_what_the_driver_can_do_and_how_the_last_sync_went(): void
    {
        $exchange = $this->exchange();
        $exchange->forceFill(['last_synced_at' => '2025-06-30 02:15:00', 'last_error' => 'Rate limited'])->save();

        $this->get(route('exchanges.edit', $exchange))
            ->assertSeeInOrder(['Nugget can read', 'Prices', 'Market list', 'Balances', 'Trades', 'Connection test', 'Last sync', '30 Jun 2025, 12:15pm', 'Last error', 'Rate limited', 'Test connection']);
    }

    public function test_the_edit_page_lists_the_balances_of_the_last_sync(): void
    {
        $exchange = $this->exchange();
        $exchange->forceFill(['last_synced_at' => '2025-06-30 02:15:00', 'last_sync_note' => 'Trades were not read.'])->save();
        ExchangeBalance::factory()->for($exchange)->create(['symbol' => 'BTC', 'units' => '0.05', 'available' => '0.03']);
        ExchangeBalance::factory()->for($exchange)->of('AUD', '1520.4567')->create();
        ExchangeBalance::factory()->of('DOGE', '777')->create();

        $this->get(route('exchanges.edit', $exchange))
            ->assertSeeInOrder(['Balances', 'Skipped', 'Trades were not read.', 'id="exchange-balances"', 'AUD', '1520.4567', '1520.4567', 'BTC', '0.05', '0.03'], false)
            ->assertDontSee('DOGE');
    }

    public function test_the_edit_page_says_when_there_are_no_balances(): void
    {
        $exchange = $this->exchange();

        $this->get(route('exchanges.edit', $exchange))->assertSee('Not synced yet.')->assertDontSee('Skipped');

        $exchange->forceFill(['last_synced_at' => now()])->save();

        $this->get(route('exchanges.edit', $exchange))->assertSee('The exchange held nothing.');
    }

    public function test_a_blank_key_and_secret_keep_the_saved_ones(): void
    {
        $exchange = $this->exchange(['api_key' => 'old-key', 'secret' => 'old-secret']);

        $this->put(route('exchanges.update', $exchange), ['label' => 'Renamed', 'api_key' => '', 'secret' => '', 'active' => '0'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('exchanges.index'));

        $exchange->refresh();
        $this->assertSame('Renamed', $exchange->label);
        $this->assertFalse($exchange->active);
        $this->assertSame('old-key', $exchange->api_key);
        $this->assertSame('old-secret', $exchange->secret);
    }

    public function test_a_new_key_or_secret_replaces_the_saved_one(): void
    {
        $exchange = $this->exchange(['api_key' => 'old-key', 'secret' => 'old-secret']);

        $this->put(route('exchanges.update', $exchange), ['label' => 'Main', 'api_key' => '', 'secret' => 'new-secret', 'active' => '1'])
            ->assertSessionHasNoErrors();

        $exchange->refresh();
        $this->assertSame('old-key', $exchange->api_key);
        $this->assertSame('new-secret', $exchange->secret);
    }

    public function test_the_driver_of_an_exchange_cannot_be_changed(): void
    {
        $this->app->scoped(ExchangeDrivers::class, fn () => new ExchangeDrivers([...ExchangeDrivers::DRIVERS, 'plain' => PlainDriver::class]));
        $exchange = $this->exchange();

        $this->put(route('exchanges.update', $exchange), $this->form(['driver' => 'plain']))->assertSessionHasNoErrors();

        $this->assertSame('btcmarkets', $exchange->refresh()->driver);
    }

    public function test_another_users_exchange_cannot_be_seen_changed_tested_or_deleted(): void
    {
        $theirs = $this->exchange(['label' => 'Theirs'], User::factory()->create());

        $this->get(route('exchanges.edit', $theirs))->assertForbidden();
        $this->put(route('exchanges.update', $theirs), $this->form())->assertForbidden();
        $this->post(route('exchanges.test', $theirs))->assertForbidden();
        $this->delete(route('exchanges.destroy', $theirs))->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->get(route('exchanges.edit', $theirs))->assertForbidden();

        $this->assertSame('Theirs', $theirs->refresh()->label);
        Http::assertNothingSent();
    }

    // Deleting

    public function test_an_exchange_that_prices_no_asset_is_deleted(): void
    {
        $exchange = $this->exchange();

        $this->get(route('exchanges.edit', $exchange))->assertSee('Delete exchange');

        $this->delete(route('exchanges.destroy', $exchange))->assertRedirect(route('exchanges.index'))->assertSessionHas('success');

        $this->assertModelMissing($exchange);
    }

    public function test_an_exchange_that_prices_an_asset_is_kept(): void
    {
        $exchange = $this->exchange();
        Asset::factory()->onExchange($exchange, 'BTC-AUD')->create();

        $this->get(route('exchanges.edit', $exchange))->assertDontSee('Delete exchange');

        $this->delete(route('exchanges.destroy', $exchange))->assertRedirect(route('exchanges.edit', $exchange))->assertSessionHas('error');

        $this->assertModelExists($exchange);
    }

    // Test connection

    public function test_a_connection_the_exchange_accepts_says_so_and_saves_nothing(): void
    {
        Http::fake([self::BALANCES => Http::response('[]')]);
        $exchange = $this->exchange(['label' => 'Main account']);

        $this->from(route('exchanges.index'))->post(route('exchanges.test', $exchange))
            ->assertRedirect(route('exchanges.index'))
            ->assertSessionHas('success', 'Main account connected: the exchange accepted the key.');

        $exchange->refresh();
        $this->assertNull($exchange->last_synced_at);
        $this->assertNull($exchange->last_error);
    }

    public function test_a_key_the_exchange_refuses_is_reported_with_its_reason(): void
    {
        Http::fake([self::BALANCES => Http::response(file_get_contents(base_path('tests/Fixtures/btcmarkets/invalid-api-key.json')), 401)]);
        $exchange = $this->exchange(['label' => 'Main account', 'api_key' => 'KEY-ABCDEF']);

        $this->post(route('exchanges.test', $exchange))
            ->assertRedirect(route('exchanges.edit', $exchange))
            ->assertSessionHas('error', 'Main account did not connect: invalid api key (InvalidAPIKey, HTTP 401)');

        $this->assertNull($exchange->refresh()->last_error);
    }

    public function test_an_exchange_that_cannot_be_reached_is_reported_without_the_key(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException('Could not resolve host: api.btcmarkets.net'));
        $exchange = $this->exchange(['label' => 'Main account', 'api_key' => 'KEY-ABCDEF']);

        $this->post(route('exchanges.test', $exchange))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Could not resolve host') && ! str_contains($message, 'KEY-ABCDEF'));
    }

    public function test_an_exchange_with_a_secret_that_is_not_base64_is_reported_without_a_call(): void
    {
        $exchange = $this->exchange(['secret' => 'not base64!']);

        $this->post(route('exchanges.test', $exchange))->assertSessionHas('error', fn (string $message) => str_contains($message, 'base64'));

        Http::assertNothingSent();
    }

    public function test_a_driver_that_cannot_test_its_connection_says_so(): void
    {
        $this->app->scoped(ExchangeDrivers::class, fn () => new ExchangeDrivers(['plain' => PlainDriver::class]));
        $exchange = $this->exchange(['driver' => 'plain']);

        $this->post(route('exchanges.test', $exchange))->assertSessionHas('warning');
        $this->get(route('exchanges.edit', $exchange))->assertOk()->assertDontSee('Test connection');
        $this->get('/exchanges')->assertOk()->assertDontSee('Test connection');
    }

    public function test_an_exchange_whose_driver_is_gone_still_has_its_pages(): void
    {
        $exchange = $this->exchange(['driver' => 'kraken', 'label' => 'Old Kraken']);

        $this->get('/exchanges')->assertOk()->assertSeeInOrder(['Old Kraken', 'kraken']);
        $this->get(route('exchanges.edit', $exchange))->assertOk()->assertSee('there is no driver called kraken');
        $this->post(route('exchanges.test', $exchange))->assertSessionHas('error');
    }
}
