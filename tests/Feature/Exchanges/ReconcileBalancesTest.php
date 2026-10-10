<?php

namespace Tests\Feature\Exchanges;

use App\Exchanges\Sync\ExchangeSync;
use App\Exchanges\Sync\ReconcileBalances;
use App\Ledger\Holdings;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\ExchangeBalance;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BalanceMismatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The comparison of an exchange's balances with the ledger. The ledger holds
 * 0.5 BTC and 1,000 ADA on the exchange.
 */
class ReconcileBalancesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Exchange $exchange;

    private Asset $bitcoin;

    private Asset $cardano;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->exchange = Exchange::factory()->for($this->user)->create(['label' => 'Main account']);
        $this->bitcoin = $this->asset('Bitcoin', 'BTC', 'BTC-AUD', '0.5');
        $this->cardano = $this->asset('Cardano', 'ADA', 'ADA-AUD', '1000');

        Http::fake([
            '*/v3/markets' => Http::response(file_get_contents(base_path('tests/Fixtures/btcmarkets/markets.json'))),
            '*/v3/trades*' => Http::response('[]'),
        ]);
    }

    private function asset(string $name, string $symbol, string $market, ?string $held = null, ?Exchange $exchange = null): Asset
    {
        $asset = Asset::factory()->onExchange($exchange ?? $this->exchange, $market)->create(['name' => $name, 'symbol' => $symbol, 'unit' => $symbol]);

        if ($held !== null) {
            Transaction::factory()->for($asset)->buy($held, '100')->create(['occurred_at' => '2025-06-01 02:00:00']);
            app(ParcelMatcher::class)->rematch($asset);
        }

        return $asset;
    }

    /**
     * Reconcile against these balances on the exchange.
     *
     * @param  array<string, string>  $balances  by symbol
     */
    private function reconcile(array $balances, ?Exchange $exchange = null): void
    {
        $exchange ??= $this->exchange;
        $exchange->balances()->delete();

        foreach ($balances as $symbol => $units) {
            ExchangeBalance::factory()->for($exchange)->of($symbol, $units)->create();
        }

        app(ReconcileBalances::class)->reconcile($exchange);
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    private function mismatches(?User $user = null): Collection
    {
        return ($user ?? $this->user)->notifications()->where('type', BalanceMismatch::class)->get();
    }

    public function test_balances_that_agree_with_the_ledger_raise_nothing(): void
    {
        $this->reconcile(['BTC' => '0.5', 'ADA' => '1000', 'AUD' => '250']);

        $this->assertCount(0, $this->mismatches());
    }

    public function test_a_difference_tells_the_owner_with_both_figures(): void
    {
        $this->reconcile(['BTC' => '0.75', 'ADA' => '1000']);

        $mismatch = $this->mismatches()->sole();

        $this->assertNull($mismatch->read_at);
        $this->assertSame([
            'exchange_id' => $this->exchange->id,
            'exchange' => 'Main account',
            'asset_id' => $this->bitcoin->id,
            'asset' => 'Bitcoin',
            'unit' => 'BTC',
            'ledger' => '0.5',
            'on_exchange' => '0.75',
            'difference' => '0.25',
        ], $mismatch->data);
    }

    public function test_an_asset_the_exchange_does_not_list_is_held_at_zero_there(): void
    {
        $this->reconcile(['BTC' => '0.5']);

        $mismatch = $this->mismatches()->sole();

        $this->assertSame('Cardano', $mismatch->data['asset']);
        $this->assertSame(['1000', '0', '-1000'], [$mismatch->data['ledger'], $mismatch->data['on_exchange'], $mismatch->data['difference']]);
    }

    public function test_a_difference_within_the_tolerance_is_let_go(): void
    {
        $this->reconcile(['BTC' => '0.500001', 'ADA' => '999.999999']);
        $this->assertCount(0, $this->mismatches());

        $this->reconcile(['BTC' => '0.50000101', 'ADA' => '1000']);
        $this->assertSame('Bitcoin', $this->mismatches()->sole()->data['asset']);

        config(['nugget.sync.balance_tolerance' => '0.01']);
        $this->reconcile(['BTC' => '0.509', 'ADA' => '1000']);
        $this->assertCount(0, $this->mismatches());
    }

    public function test_there_is_one_notification_for_an_asset_however_many_syncs_find_it(): void
    {
        $this->reconcile(['BTC' => '0.75', 'ADA' => '1000']);
        $first = $this->mismatches()->sole();
        $first->markAsRead();

        $this->reconcile(['BTC' => '0.75', 'ADA' => '1000']);
        $this->reconcile(['BTC' => '0.75', 'ADA' => '1000']);

        // The same difference: the one notification, still read.
        $again = $this->mismatches()->sole();
        $this->assertSame($first->id, $again->id);
        $this->assertNotNull($again->read_at);
    }

    public function test_a_difference_that_changes_updates_the_notification_and_marks_it_unread(): void
    {
        $this->reconcile(['BTC' => '0.75', 'ADA' => '1000']);
        $first = $this->mismatches()->sole();
        $first->markAsRead();

        $this->reconcile(['BTC' => '0.8', 'ADA' => '1000']);

        $changed = $this->mismatches()->sole();
        $this->assertSame($first->id, $changed->id);
        $this->assertSame('0.3', $changed->data['difference']);
        $this->assertNull($changed->read_at);
    }

    public function test_each_asset_that_is_out_has_its_own_notification(): void
    {
        $this->reconcile(['BTC' => '0.75', 'ADA' => '900']);

        $this->assertEqualsCanonicalizing(['Bitcoin', 'Cardano'], $this->mismatches()->pluck('data.asset')->all());
    }

    public function test_the_notification_goes_when_the_two_agree_again(): void
    {
        $this->reconcile(['BTC' => '0.75', 'ADA' => '900']);

        // The owner enters the missing quarter of a bitcoin.
        Transaction::factory()->for($this->bitcoin)->transferIn('0.25')->create(['occurred_at' => '2025-06-02 02:00:00']);
        app(ParcelMatcher::class)->rematch($this->bitcoin);
        $this->reconcile(['BTC' => '0.75', 'ADA' => '900']);

        $this->assertSame('Cardano', $this->mismatches()->sole()->data['asset']);
    }

    public function test_reconciling_never_changes_the_ledger(): void
    {
        $ledger = fn () => json_encode(DB::table('transactions')->orderBy('id')->get()).json_encode(DB::table('parcels')->orderBy('id')->get());
        $before = $ledger();

        $this->reconcile(['BTC' => '9', 'ADA' => '1']);

        $this->assertSame($before, $ledger());
        $this->assertCount(2, $this->mismatches());
    }

    public function test_an_asset_that_only_shares_a_market_for_its_price_is_not_compared(): void
    {
        $wallet = $this->asset('Bitcoin cold wallet', 'BTC', 'BTC-AUD', '2');

        $this->reconcile(['BTC' => '0.5', 'ADA' => '1000']);

        $this->assertCount(0, $this->mismatches());
        $this->assertSame('2', (string) app(Holdings::class)->forAsset($wallet)->units);
    }

    public function test_a_balance_of_a_coin_with_no_asset_is_not_looked_at(): void
    {
        $this->reconcile(['BTC' => '0.5', 'ADA' => '1000', 'XRP' => '0.1', 'ETH' => '0.0000217']);

        $this->assertCount(0, $this->mismatches());
    }

    public function test_the_exchanges_own_name_for_the_coin_is_used_not_the_assets_symbol(): void
    {
        $this->bitcoin->update(['symbol' => 'XBT']);

        $this->reconcile(['BTC' => '0.5', 'ADA' => '1000']);

        $this->assertCount(0, $this->mismatches());
    }

    public function test_the_assets_symbol_is_used_when_the_markets_are_not_known(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/v3/markets' => Http::response('Service unavailable', 503)]);

        $this->reconcile(['BTC' => '0.5', 'ADA' => '900']);

        $this->assertSame('Cardano', $this->mismatches()->sole()->data['asset']);
    }

    public function test_another_exchange_and_another_user_are_left_alone(): void
    {
        $second = Exchange::factory()->for($this->user)->create();
        $this->asset('Litecoin', 'LTC', 'LTC-AUD', '3', $second);
        $this->reconcile(['LTC' => '1'], $second);
        $theirs = Exchange::factory()->create();
        $this->asset('Their bitcoin', 'BTC', 'BTC-AUD', '1', $theirs);
        $this->reconcile(['BTC' => '4'], $theirs);

        // This exchange agrees, which must not clear the other's notification.
        $this->reconcile(['BTC' => '0.5', 'ADA' => '1000']);

        $this->assertSame('Litecoin', $this->mismatches()->sole()->data['asset']);
        $this->assertSame('Their bitcoin', $this->mismatches($theirs->user)->sole()->data['asset']);
    }

    public function test_an_asset_moved_off_the_exchange_loses_its_notification(): void
    {
        $this->reconcile(['BTC' => '0.75', 'ADA' => '1000']);

        $this->bitcoin->update(['exchange_id' => null, 'market_id' => null]);
        $this->reconcile(['BTC' => '0.75', 'ADA' => '1000']);

        $this->assertCount(0, $this->mismatches());
    }

    // As a step of the sync

    public function test_a_sync_compares_the_balances_it_has_just_read(): void
    {
        Http::fake(['*/v3/accounts/me/balances' => Http::response(
            '[{"assetName":"BTC","balance":"0.5","available":"0.5","locked":"0"},{"assetName":"ADA","balance":"1200","available":"1200","locked":"0"}]',
        )]);

        $this->assertTrue(app(ExchangeSync::class)->run($this->exchange));

        $mismatch = $this->mismatches()->sole();
        $this->assertSame(['Cardano', '200'], [$mismatch->data['asset'], $mismatch->data['difference']]);
        $this->assertNull($this->exchange->refresh()->last_sync_note);
    }

    public function test_a_sync_that_fails_compares_nothing(): void
    {
        Http::fake(['*/v3/accounts/me/balances' => Http::response('{"code":"InvalidAPIKey","message":"invalid api key"}', 401)]);
        ExchangeBalance::factory()->for($this->exchange)->of('BTC', '9')->create();

        $this->assertFalse(app(ExchangeSync::class)->run($this->exchange));

        $this->assertCount(0, $this->mismatches());
    }

    // The way to settle one

    public function test_the_links_fill_in_a_transfer_or_an_adjustment(): void
    {
        $this->reconcile(['BTC' => '0.75', 'ADA' => '900']);
        $byAsset = $this->mismatches()->keyBy(fn (DatabaseNotification $n) => $n->data['asset']);

        $more = BalanceMismatch::links($byAsset['Bitcoin']->data);
        $less = BalanceMismatch::links($byAsset['Cardano']->data);

        $this->assertSame('Transfer in', $more['transferLabel']);
        $this->assertSame(route('transactions.create', ['asset' => $this->bitcoin->id, 'type' => 'transfer_in', 'units' => '0.25']), $more['transfer']);
        $this->assertSame(route('transactions.create', ['asset' => $this->bitcoin->id, 'type' => 'adjust', 'units' => '0.25']), $more['adjust']);

        $this->assertSame('Transfer out', $less['transferLabel']);
        $this->assertSame(route('transactions.create', ['asset' => $this->cardano->id, 'type' => 'transfer_out', 'units' => '100']), $less['transfer']);
        $this->assertSame(route('transactions.create', ['asset' => $this->cardano->id, 'type' => 'adjust', 'units' => '-100']), $less['adjust']);
    }

    public function test_a_link_opens_the_transaction_form_filled_in(): void
    {
        $this->reconcile(['BTC' => '0.5', 'ADA' => '900']);
        $links = BalanceMismatch::links($this->mismatches()->sole()->data);

        $this->actingAs($this->user)->get($links['adjust'])
            ->assertOk()
            ->assertSee('<option value="'.$this->cardano->id.'" selected>', false)
            ->assertSee('<option value="adjust" selected>', false)
            ->assertSee('name="units" value="-100"', false);

        $this->actingAs($this->user)->get($links['transfer'])
            ->assertSee('<option value="transfer_out" selected>', false)
            ->assertSee('name="units" value="100"', false);
    }

    public function test_the_form_passes_over_a_type_or_units_it_cannot_use(): void
    {
        $url = fn (array $query) => route('transactions.create', ['asset' => $this->bitcoin->id, ...$query]);

        $this->actingAs($this->user)->get($url(['type' => 'nonsense', 'units' => '1<script>']))
            ->assertOk()
            ->assertSee('<option value="buy" selected>', false)
            ->assertSee('name="units" value=""', false);

        // Only an adjustment may be negative.
        $this->actingAs($this->user)->get($url(['type' => 'sell', 'units' => '-1']))->assertSee('name="units" value=""', false);
        $this->actingAs($this->user)->get($url(['type' => ['a'], 'units' => ['b']]))->assertOk();
    }

    public function test_the_exchange_page_lists_the_mismatches_with_their_links(): void
    {
        $this->reconcile(['BTC' => '0.75', 'ADA' => '1000']);
        $links = BalanceMismatch::links($this->mismatches()->sole()->data);

        $this->actingAs($this->user)->get(route('exchanges.edit', $this->exchange))
            ->assertOk()
            ->assertSeeInOrder(['id="balance-mismatches"', 'Bitcoin', '0.5', '0.75', '0.25 BTC', 'Transfer in', 'Adjust'], false)
            ->assertSee('href="'.e($links['transfer']).'"', false)
            ->assertSee('href="'.e($links['adjust']).'"', false);

        $this->reconcile(['BTC' => '0.5', 'ADA' => '1000']);

        $this->actingAs($this->user)->get(route('exchanges.edit', $this->exchange))->assertDontSee('id="balance-mismatches"', false);
    }
}
