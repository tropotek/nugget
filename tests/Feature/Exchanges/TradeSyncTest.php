<?php

namespace Tests\Feature\Exchanges;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Exchanges\Contracts\ReportsTrades;
use App\Exchanges\Drivers\BtcMarkets;
use App\Exchanges\ExchangeDrivers;
use App\Exchanges\Sync\ExchangeSync;
use App\Exchanges\Sync\SyncOutcome;
use App\Exchanges\Sync\SyncTrades;
use App\Exchanges\Trade;
use App\Ledger\Holdings;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NewTrades;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\PlainDriver;
use Tests\Fakes\TradingDriver;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * The trade step of a sync, against two pages in the shape BTC Markets gives
 * with made-up trades:
 *
 *   1000000001  10 Jun  BTC-AUD  buy   0.02 at $150,000, fee $25.50
 *   1000000002  11 Jun  ADA-AUD  buy   1000 at $1.05, fee $8.925
 *   1000000003  12 Jun  XRP-BTC  sell  (a coin for a coin)
 *   1000000004  15 Jun  BTC-AUD  sell  0.005 at $160,000, fee $6.80000001
 *   1000000005  20 Jun  LTC-AUD  buy   2 at $170, fee $2.89
 */
class TradeSyncTest extends TestCase
{
    use RefreshDatabase;

    private const TRADES = 'https://api.btcmarkets.net/v3/trades';

    private User $user;

    private Exchange $exchange;

    private Asset $bitcoin;

    private Asset $cardano;

    /** @var list<string> the `after` of each call for trades */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-06-30 03:07:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->exchange = Exchange::factory()->for($this->user)->create(['label' => 'Main account']);
        $this->bitcoin = $this->asset('Bitcoin', 'BTC', 'BTC-AUD');
        $this->cardano = $this->asset('Cardano', 'ADA', 'ADA-AUD');

        Http::fake(['*/v3/accounts/me/balances' => Http::response('[]')]);
    }

    private function asset(string $name, string $symbol, string $market, ?Exchange $exchange = null): Asset
    {
        return Asset::factory()->onExchange($exchange ?? $this->exchange, $market)->create(['name' => $name, 'symbol' => $symbol, 'unit' => $symbol]);
    }

    /**
     * The exchange gives the two recorded pages, then nothing.
     *
     * @param  array<string, string>  $pages  by the `after` asked for; these replace the recorded ones
     */
    private function fakeTrades(array $pages = []): void
    {
        $pages += [
            '0' => file_get_contents(base_path('tests/Fixtures/btcmarkets/trades-page-1.json')),
            '1000000003' => file_get_contents(base_path('tests/Fixtures/btcmarkets/trades-page-2.json')),
        ];

        Http::fake(function (Request $request) use ($pages) {
            if (! str_starts_with($request->url(), self::TRADES)) {
                return null;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->asked[] = $query['after'];

            return Http::response($pages[$query['after']] ?? '[]');
        });
    }

    private function sync(?Exchange $exchange = null): bool
    {
        return $this->app->make(ExchangeSync::class)->run($exchange ?? $this->exchange) === SyncOutcome::Synced;
    }

    /**
     * An asset's transactions, oldest first, as "id type units @ price fee total".
     *
     * @return list<string>
     */
    private function ledger(Asset $asset): array
    {
        return $asset->transactions()->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn (Transaction $t) => "{$t->external_id} {$t->type->value} {$t->units} @ {$t->unit_price} {$t->fee->toFixed(2)} {$t->total->toFixed(2)}")
            ->all();
    }

    private function trade(string $id, string $market, TransactionType $type, string $units, string $price, string $fee, string $feeSymbol): Trade
    {
        return new Trade(
            $id, $market, 'AUD', $type, CarbonImmutable::parse('2025-06-01 02:00:00')->addMinutes((int) $id),
            Decimal::of($units), Decimal::of($price), Decimal::of($fee), $feeSymbol,
        );
    }

    /**
     * @param  list<Trade>  $trades
     */
    private function tradingExchange(array $trades, int $perPage = 2): Exchange
    {
        TradingDriver::$trades = $trades;
        TradingDriver::$perPage = $perPage;
        $this->app->scoped(ExchangeDrivers::class, fn () => new ExchangeDrivers(['trading' => TradingDriver::class]));

        return Exchange::factory()->for($this->user)->create(['driver' => 'trading']);
    }

    // The driver

    public function test_btc_markets_lists_trades_oldest_first(): void
    {
        $this->fakeTrades();
        $driver = new BtcMarkets($this->exchange);

        $trades = $driver->tradesAfter(null);

        $this->assertInstanceOf(ReportsTrades::class, $driver);
        $this->assertSame(['1000000001', '1000000002', '1000000003'], array_column($trades, 'id'));

        $first = $trades[0];
        $this->assertSame('BTC-AUD', $first->market);
        $this->assertSame('AUD', $first->quote);
        $this->assertSame('AUD', $first->feeSymbol);
        $this->assertSame(TransactionType::Buy, $first->type);
        $this->assertSame('2025-06-10 02:00:00.123', $first->at->format('Y-m-d H:i:s.v'));
        $this->assertSame(['0.02', '150000', '25.5'], [(string) $first->units, (string) $first->price, (string) $first->fee]);

        $this->assertSame(TransactionType::Sell, $trades[2]->type);
        $this->assertSame('BTC', $trades[2]->quote);
        $this->assertSame('BTC', $trades[2]->feeSymbol);
    }

    public function test_trades_are_asked_for_after_an_id_with_a_signed_call(): void
    {
        $this->travelTo('2025-06-30 03:07:30.123');
        $this->fakeTrades();
        $driver = new BtcMarkets(Exchange::factory()->create(['api_key' => 'my-key', 'secret' => base64_encode('my-secret')]));

        $driver->tradesAfter(null);
        $driver->tradesAfter('1000000003');

        // The query string is not part of what is signed.
        $signature = base64_encode(hash_hmac('sha512', 'GET/v3/trades1751252850123', 'my-secret', true));

        Http::assertSent(fn (Request $request) => $request->url() === self::TRADES.'?after=0&limit=200'
            && $request->header('BM-AUTH-APIKEY') === ['my-key']
            && $request->header('BM-AUTH-SIGNATURE') === [$signature]);
        Http::assertSent(fn (Request $request) => $request->url() === self::TRADES.'?after=1000000003&limit=200');
    }

    public function test_a_trade_of_the_wrong_shape_throws(): void
    {
        $this->fakeTrades(['0' => '[{"id":"1","marketId":"BTC-AUD","timestamp":"2025-06-10T02:00:00.000000Z","price":"1","amount":"1","side":"Sideways","fee":"0"}]']);

        $this->expectException(UnexpectedValueException::class);

        (new BtcMarkets($this->exchange))->tradesAfter(null);
    }

    // The sync

    public function test_a_sync_stores_each_trade_as_a_buy_or_a_sell_of_the_asset_on_its_market(): void
    {
        $this->fakeTrades();

        $this->assertTrue($this->sync());

        // The fee is rounded to the cent, and is in the total.
        $this->assertSame([
            '1000000001 buy 0.02 @ 150000 25.50 3025.50',
            '1000000004 sell 0.005 @ 160000 6.80 793.20',
        ], $this->ledger($this->bitcoin));
        $this->assertSame(['1000000002 buy 1000 @ 1.05 8.93 1058.93'], $this->ledger($this->cardano));

        $buy = $this->bitcoin->transactions()->where('external_id', '1000000001')->sole();
        $this->assertSame(TransactionSource::Exchange, $buy->source);
        $this->assertTrue($buy->isLocked());
        $this->assertFalse($buy->estimated);
        $this->assertSame('2025-06-10 02:00:00', $buy->occurred_at->toDateTimeString());
    }

    public function test_the_new_trades_go_through_the_matcher(): void
    {
        $this->fakeTrades();

        $this->sync();

        $holding = app(Holdings::class)->forAsset($this->bitcoin);
        $sale = $this->bitcoin->transactions()->where('type', TransactionType::Sell)->sole();

        // A quarter of the $3,025.50 parcel was sold: $756.37 of cost against $793.20.
        $this->assertSame('0.015', (string) $holding->units);
        $this->assertSame('2269.13', $holding->costBase->toFixed(2));
        $this->assertSame('36.83', $sale->disposals()->sole()->gain->toFixed(2));
    }

    public function test_a_sync_pages_through_the_trades_and_remembers_the_last(): void
    {
        $this->fakeTrades();

        $this->sync();

        $this->assertSame(['0', '1000000003', '1000000005'], $this->asked);
        $this->assertSame('1000000005', $this->exchange->refresh()->trade_cursor);
    }

    public function test_a_second_sync_asks_only_for_what_is_new_and_adds_nothing(): void
    {
        $this->fakeTrades();
        $this->sync();
        $this->asked = [];

        $this->assertTrue($this->sync());

        $this->assertSame(['1000000005'], $this->asked);
        $this->assertSame(3, Transaction::count());
        $this->assertNull($this->exchange->refresh()->last_sync_note);
    }

    public function test_a_trade_already_in_the_ledger_is_not_stored_twice(): void
    {
        $this->fakeTrades();
        $this->sync();

        $this->artisan('nugget:sync-exchanges', ['--all-trades' => true])->assertSuccessful();

        $this->assertSame(['0', '1000000003', '1000000005', '0', '1000000003', '1000000005'], $this->asked);
        $this->assertSame(3, Transaction::count());
        $this->assertSame('0.015', (string) app(Holdings::class)->forAsset($this->bitcoin)->units);
    }

    public function test_trades_with_no_asset_or_not_in_aud_are_passed_over_and_noted(): void
    {
        $this->fakeTrades();

        $this->sync();

        $this->exchange->refresh();
        $this->assertSame(
            '2 trades were passed over, on a market with no asset or not priced in AUD: LTC-AUD, XRP-BTC.',
            $this->exchange->last_sync_note,
        );
        $this->assertNull($this->exchange->last_error);
        $this->assertSame(3, Transaction::count());
    }

    public function test_an_asset_added_later_gets_its_trades_when_they_are_all_read_again(): void
    {
        $this->fakeTrades();
        $this->sync();
        $litecoin = $this->asset('Litecoin', 'LTC', 'LTC-AUD');

        $this->artisan('nugget:sync-exchanges', ['--exchange' => $this->exchange->id, '--all-trades' => true])->assertSuccessful();

        $this->assertSame(['1000000005 buy 2 @ 170 2.89 342.89'], $this->ledger($litecoin));
        $this->assertSame(4, Transaction::count());
    }

    public function test_where_two_assets_share_a_market_its_trades_go_to_the_one_added_first(): void
    {
        $this->fakeTrades();
        $wallet = $this->asset('Bitcoin cold wallet', 'BTC', 'BTC-AUD');
        $theirs = $this->asset('Their Litecoin', 'LTC', 'LTC-AUD', Exchange::factory()->create());

        $this->sync();

        $this->assertCount(2, $this->ledger($this->bitcoin));
        $this->assertSame([], $this->ledger($wallet));
        $this->assertSame([], $this->ledger($theirs));
    }

    public function test_a_sale_of_more_than_is_held_refuses_its_whole_page_and_is_tried_again(): void
    {
        // The second page sells 0.5 BTC, and 0.02 is held.
        $sale = '[{"id":"1000000004","marketId":"BTC-AUD","timestamp":"2025-06-15T04:30:00.000000Z","price":"160000","amount":"0.5","side":"Ask","fee":"680"},'
            .'{"id":"1000000003b","marketId":"ADA-AUD","timestamp":"2025-06-14T04:30:00.000000Z","price":"1","amount":"5","side":"Bid","fee":"0"}]';
        $this->fakeTrades(['1000000003' => $sale]);

        $this->assertFalse($this->sync());

        $this->exchange->refresh();
        $this->assertSame('Bitcoin: the sell of 0.5 BTC on 15 Jun 2025 is more than the 0.02 BTC held.', $this->exchange->last_error);
        $this->assertSame('1000000003', $this->exchange->trade_cursor);
        $this->assertNull($this->exchange->last_synced_at);
        // The first page is kept, and nothing of the second.
        $this->assertSame(2, Transaction::count());
        $this->assertSame('0.02', (string) app(Holdings::class)->forAsset($this->bitcoin)->units);

        // The missing coins are entered by hand, and the next sync carries on from the same place.
        Transaction::factory()->for($this->bitcoin)->transferIn('1')->create(['occurred_at' => '2025-06-01 02:00:00']);
        $this->asked = [];

        $this->assertTrue($this->sync());

        $this->assertSame(['1000000003', '1000000004', '1000000004'], array_slice([...$this->asked, '1000000004'], 0, 3));
        $this->assertSame('0.52', (string) app(Holdings::class)->forAsset($this->bitcoin)->units);
        $this->assertNull($this->exchange->refresh()->last_error);
    }

    public function test_a_failure_to_read_the_trades_is_recorded_and_the_balances_are_kept(): void
    {
        Http::fake([
            '*/v3/trades*' => Http::response('{"code":"TooManyRequests","message":"slow down"}', 429),
        ]);

        $this->assertFalse($this->sync());

        $this->assertSame('slow down (TooManyRequests, HTTP 429)', $this->exchange->refresh()->last_error);
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_fee_charged_in_the_coin_is_converted_to_aud_at_the_trade_price(): void
    {
        $exchange = $this->tradingExchange([
            $this->trade('1', 'BTC-AUD', TransactionType::Buy, '0.5', '100000', '0.0005', 'BTC'),
            $this->trade('2', 'BTC-AUD', TransactionType::Sell, '0.1', '120000', '0.00010004', 'BTC'),
        ]);
        $bitcoin = $this->asset('Bitcoin there', 'BTC', 'BTC-AUD', $exchange);

        $this->assertTrue($this->sync($exchange));

        // 0.0005 BTC at $100,000 is $50; 0.00010004 BTC at $120,000 is $12.0048.
        $this->assertSame([
            '1 buy 0.5 @ 100000 50.00 50050.00',
            '2 sell 0.1 @ 120000 12.00 11988.00',
        ], $this->ledger($bitcoin));
    }

    public function test_a_fee_in_some_other_currency_is_refused(): void
    {
        $exchange = $this->tradingExchange([$this->trade('1', 'BTC-AUD', TransactionType::Buy, '0.5', '100000', '3', 'BNB')]);
        $this->asset('Bitcoin there', 'BTC', 'BTC-AUD', $exchange);

        $this->assertFalse($this->sync($exchange));

        $this->assertSame('Trade 1 on BTC-AUD has a fee in BNB, which is neither AUD nor BTC.', $exchange->refresh()->last_error);
        $this->assertSame(0, Transaction::count());
        $this->assertNull($exchange->trade_cursor);
    }

    public function test_one_sync_reads_so_many_pages_and_the_next_carries_on(): void
    {
        $trades = array_map(
            fn (int $id) => $this->trade((string) $id, 'BTC-AUD', TransactionType::Buy, '1', '100', '0', 'AUD'),
            range(1, SyncTrades::MAX_PAGES + 2),
        );
        $exchange = $this->tradingExchange($trades, perPage: 1);
        $bitcoin = $this->asset('Bitcoin there', 'BTC', 'BTC-AUD', $exchange);

        $this->assertTrue($this->sync($exchange));

        $this->assertSame(SyncTrades::MAX_PAGES, $bitcoin->transactions()->count());
        $this->assertStringEndsWith('More trades remain: the next sync carries on.', $exchange->refresh()->last_sync_note);

        $this->assertTrue($this->sync($exchange));

        $this->assertSame(SyncTrades::MAX_PAGES + 2, $bitcoin->transactions()->count());
        $this->assertStringNotContainsString('More trades remain', $exchange->refresh()->last_sync_note);
    }

    public function test_an_exchange_whose_driver_lists_no_trades_notes_the_skip(): void
    {
        $this->app->scoped(ExchangeDrivers::class, fn () => new ExchangeDrivers(['plain' => PlainDriver::class]));
        $exchange = Exchange::factory()->create(['driver' => 'plain']);

        $this->assertTrue($this->sync($exchange));

        $this->assertSame(
            'Balances were not read: A plain exchange has no call for them. Trades were not read: A plain exchange has no call for them.',
            $exchange->refresh()->last_sync_note,
        );
    }

    public function test_trades_a_backfill_has_already_brought_in_are_not_stored_again(): void
    {
        // The exchange's own report covered everything up to the end of 11 June.
        $this->exchange->forceFill(['trades_after' => '2025-06-12 00:00:00'])->save();
        Transaction::factory()->for($this->bitcoin)->buy('0.02', '150000', '25.5')->fromExchange('csv-900000003')->create(['occurred_at' => '2025-06-10 02:00:00']);
        $this->fakeTrades();

        $this->assertTrue($this->sync());

        // The two trades of 10 and 11 June are left to the report; the sale of the 15th is new.
        $this->assertSame(['csv-900000003 buy 0.02 @ 150000 25.50 3025.50', '1000000004 sell 0.005 @ 160000 6.80 793.20'], $this->ledger($this->bitcoin));
        $this->assertSame([], $this->ledger($this->cardano));
        // They were read all the same, and are not read again.
        $this->assertSame('1000000005', $this->exchange->refresh()->trade_cursor);
        $this->assertStringNotContainsString('ADA-AUD', (string) $this->exchange->last_sync_note);
    }

    public function test_a_trade_in_the_same_second_as_the_end_of_the_report_is_the_reports_last_row(): void
    {
        // The report gives times to the second; the API gives the Cardano buy as 22:40:10.5.
        $this->exchange->forceFill(['trades_after' => '2025-06-11 22:40:10'])->save();
        Transaction::factory()->for($this->bitcoin)->buy('0.02', '150000', '25.5')->fromExchange('csv-900000003')->create(['occurred_at' => '2025-06-10 02:00:00']);
        Transaction::factory()->for($this->cardano)->buy('1000', '1.05', '8.93')->fromExchange('csv-900000007')->create(['occurred_at' => '2025-06-11 22:40:10']);
        $this->fakeTrades();

        $this->assertTrue($this->sync());

        // It is not stored a second time under the API's own trade id.
        $this->assertCount(1, $this->ledger($this->cardano));
        $this->assertStringStartsWith('csv-900000007 ', $this->ledger($this->cardano)[0]);
    }

    // Telling the owner

    public function test_a_sync_that_stores_trades_tells_the_owner_once(): void
    {
        $this->fakeTrades();

        $this->sync();

        $notification = $this->user->notifications()->where('type', NewTrades::class)->sole();

        // Two pages, one notification: 2 on Bitcoin and 1 on Cardano. The two passed over are not counted.
        $this->assertNull($notification->read_at);
        $this->assertSame([
            'exchange_id' => $this->exchange->id,
            'exchange' => 'Main account',
            'count' => 3,
            'assets' => [
                ['asset_id' => $this->bitcoin->id, 'asset' => 'Bitcoin', 'count' => 2],
                ['asset_id' => $this->cardano->id, 'asset' => 'Cardano', 'count' => 1],
            ],
        ], $notification->data);
    }

    public function test_a_sync_that_stores_nothing_tells_nobody(): void
    {
        $this->fakeTrades();
        $this->sync();
        $this->user->notifications()->delete();

        // Nothing new, and then everything read again with nothing to add.
        $this->sync();
        $this->artisan('nugget:sync-exchanges', ['--all-trades' => true]);

        $this->assertSame(0, $this->user->notifications()->where('type', NewTrades::class)->count());
    }

    public function test_the_trades_of_a_page_that_is_refused_are_not_announced_but_those_kept_are(): void
    {
        $sale = '[{"id":"1000000004","marketId":"BTC-AUD","timestamp":"2025-06-15T04:30:00.000000Z","price":"160000","amount":"0.5","side":"Ask","fee":"680"}]';
        $this->fakeTrades(['1000000003' => $sale]);

        $this->assertFalse($this->sync());

        $this->assertSame(2, $this->user->notifications()->where('type', NewTrades::class)->sole()->data['count']);
    }
}
