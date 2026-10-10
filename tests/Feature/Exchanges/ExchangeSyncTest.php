<?php

namespace Tests\Feature\Exchanges;

use App\Exchanges\Balance;
use App\Exchanges\Contracts\ReportsBalances;
use App\Exchanges\Drivers\BtcMarkets;
use App\Exchanges\ExchangeDrivers;
use App\Exchanges\Sync\ExchangeSync;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\ExchangeBalance;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Fakes\PlainDriver;
use Tests\TestCase;
use UnexpectedValueException;

/**
 * The sync of an exchange, against a response in the shape BTC Markets gives
 * with made-up amounts. "Now" is 03:07 UTC on 30 June 2025.
 */
class ExchangeSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BALANCES = 'https://api.btcmarkets.net/v3/accounts/me/balances';

    private Exchange $exchange;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-06-30 03:07:00');
        $this->exchange = Exchange::factory()->create(['label' => 'Main account']);
    }

    private function fakeBalances(?string $body = null, int $status = 200): void
    {
        Http::fake([self::BALANCES => Http::response($body ?? file_get_contents(base_path('tests/Fixtures/btcmarkets/balances.json')), $status)]);
    }

    private function sync(?Exchange $exchange = null): bool
    {
        return $this->app->make(ExchangeSync::class)->run($exchange ?? $this->exchange);
    }

    /**
     * @return array<string, array{string, string}> the stored balances as symbol => [units, available]
     */
    private function stored(?Exchange $exchange = null): array
    {
        return ($exchange ?? $this->exchange)->balances()->orderBy('symbol')->get()
            ->mapWithKeys(fn (ExchangeBalance $balance) => [$balance->symbol => [(string) $balance->units, (string) $balance->available]])
            ->all();
    }

    // The driver

    public function test_btc_markets_reports_the_accounts_balances(): void
    {
        $this->fakeBalances();
        $driver = new BtcMarkets($this->exchange);

        $balances = $driver->balances();

        $this->assertInstanceOf(ReportsBalances::class, $driver);
        $this->assertCount(6, $balances);
        $this->assertContainsOnlyInstancesOf(Balance::class, $balances);
        $this->assertSame(['AAVE', 'ADA', 'AUD', 'BTC', 'ETH', 'XRP'], array_column($balances, 'symbol'));
        // Rounded to the eight places Nugget keeps.
        $this->assertSame('0.05', (string) $balances[3]->units);
        $this->assertSame('0.03', (string) $balances[3]->available);

        Http::assertSent(fn (Request $request) => $request->url() === self::BALANCES && $request->hasHeader('BM-AUTH-SIGNATURE'));
    }

    public function test_an_answer_that_is_not_a_list_of_balances_throws(): void
    {
        $this->fakeBalances('{"assetName":"BTC"}');

        $this->expectException(UnexpectedValueException::class);

        (new BtcMarkets($this->exchange))->balances();
    }

    public function test_a_balance_that_is_not_a_number_throws(): void
    {
        $this->fakeBalances('[{"assetName":"BTC","balance":"lots","available":"0","locked":"0"}]');

        $this->expectException(InvalidArgumentException::class);

        (new BtcMarkets($this->exchange))->balances();
    }

    // The sync

    public function test_a_sync_stores_the_balances_held_and_the_aud_balance(): void
    {
        $this->fakeBalances();

        $this->assertTrue($this->sync());

        $this->assertSame([
            'ADA' => ['3018', '3018'],
            'AUD' => ['1520.4567', '1020.4567'],
            'BTC' => ['0.05', '0.03'],
            'XRP' => ['0.1', '0.1'],
        ], $this->stored());

        $this->exchange->refresh();
        $this->assertSame('1520.46', $this->exchange->aud_balance->toFixed(2));
        $this->assertSame('2025-06-30 03:07:00', $this->exchange->last_synced_at->toDateTimeString());
        $this->assertNull($this->exchange->last_error);
        $this->assertNull($this->exchange->last_sync_note);
    }

    public function test_a_sync_replaces_the_balances_of_the_one_before(): void
    {
        ExchangeBalance::factory()->for($this->exchange)->of('LTC', '4')->create();
        ExchangeBalance::factory()->for($this->exchange)->of('BTC', '9')->create();
        $other = ExchangeBalance::factory()->of('LTC', '7')->create();
        $this->exchange->forceFill(['aud_balance' => '50.00', 'last_error' => 'Rate limited', 'last_sync_note' => 'An old note.'])->save();
        $this->fakeBalances('[{"assetName":"BTC","balance":"0.5","available":"0.5","locked":"0"}]');

        $this->assertTrue($this->sync());

        $this->assertSame(['BTC' => ['0.5', '0.5']], $this->stored());
        $this->assertSame(['LTC' => ['7', '7']], $this->stored($other->exchange));

        // No AUD was listed, so none is held.
        $this->exchange->refresh();
        $this->assertTrue($this->exchange->aud_balance->isZero());
        $this->assertNull($this->exchange->last_error);
        $this->assertNull($this->exchange->last_sync_note);
    }

    public function test_a_sync_never_touches_the_ledger(): void
    {
        $this->fakeBalances();
        $asset = Asset::factory()->onExchange($this->exchange, 'BTC-AUD')->create(['symbol' => 'BTC']);
        Transaction::factory()->for($asset)->buy('1', '100')->create();

        $this->sync();

        $this->assertSame(1, Transaction::count());
        $this->assertSame('1', (string) Transaction::sole()->units);
    }

    public function test_a_key_the_exchange_refuses_is_recorded_on_the_exchange_and_not_thrown(): void
    {
        Log::spy();
        ExchangeBalance::factory()->for($this->exchange)->of('BTC', '9')->create();
        $this->exchange->forceFill(['aud_balance' => '50.00', 'last_synced_at' => '2025-06-29 03:00:00'])->save();
        $this->fakeBalances(file_get_contents(base_path('tests/Fixtures/btcmarkets/invalid-api-key.json')), 401);

        $this->assertFalse($this->sync());

        // What the last good sync found is kept.
        $this->exchange->refresh();
        $this->assertSame('invalid api key (InvalidAPIKey, HTTP 401)', $this->exchange->last_error);
        $this->assertSame('2025-06-29 03:00:00', $this->exchange->last_synced_at->toDateTimeString());
        $this->assertSame('50.00', $this->exchange->aud_balance->toFixed(2));
        $this->assertSame(['BTC' => ['9', '9']], $this->stored());

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'Main account'));
    }

    public function test_a_response_of_the_wrong_shape_is_recorded_and_changes_nothing(): void
    {
        ExchangeBalance::factory()->for($this->exchange)->of('BTC', '9')->create();
        $this->fakeBalances('[{"assetName":"ADA","balance":"5","available":"5"},{"assetName":"BTC","balance":"lots"}]');

        $this->assertFalse($this->sync());

        $this->assertNotNull($this->exchange->refresh()->last_error);
        $this->assertSame(['BTC' => ['9', '9']], $this->stored());
    }

    public function test_a_sync_that_works_clears_the_last_error(): void
    {
        $this->exchange->forceFill(['last_error' => 'invalid api key (InvalidAPIKey, HTTP 401)'])->save();
        $this->fakeBalances();

        $this->assertTrue($this->sync());

        $this->assertNull($this->exchange->refresh()->last_error);
    }

    public function test_an_exchange_whose_driver_reports_no_balances_keeps_its_last_and_notes_the_skip(): void
    {
        Log::spy();
        $this->app->scoped(ExchangeDrivers::class, fn () => new ExchangeDrivers(['plain' => PlainDriver::class]));
        $exchange = Exchange::factory()->create(['driver' => 'plain']);
        $exchange->forceFill(['aud_balance' => '50.00'])->save();
        ExchangeBalance::factory()->for($exchange)->of('BTC', '9')->create();

        $this->assertTrue($this->sync($exchange));

        $exchange->refresh();
        $this->assertSame('Balances were not read: A plain exchange has no call for them.', $exchange->last_sync_note);
        $this->assertNull($exchange->last_error);
        $this->assertNotNull($exchange->last_synced_at);
        $this->assertSame('50.00', $exchange->aud_balance->toFixed(2));
        $this->assertSame(['BTC' => ['9', '9']], $this->stored($exchange));

        Log::shouldNotHaveReceived('warning');
        Http::assertNothingSent();
    }

    public function test_an_exchange_with_no_driver_records_that(): void
    {
        $exchange = Exchange::factory()->create(['driver' => 'kraken']);

        $this->assertFalse($this->sync($exchange));

        $this->assertSame("There is no exchange driver called 'kraken'.", $exchange->refresh()->last_error);
    }

    // The table

    public function test_the_balances_table_has_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'exchange_id', 'symbol', 'units', 'available', 'created_at', 'updated_at'],
            Schema::getColumnListing('exchange_balances'),
        );
    }

    public function test_a_balance_belongs_to_its_exchanges_owner_and_goes_with_the_exchange(): void
    {
        $balance = ExchangeBalance::factory()->for($this->exchange)->create();

        $this->assertSame($this->exchange->user_id, $balance->ownerId());
        $this->assertTrue($this->exchange->user->can('view', $balance));
        $this->assertFalse(User::factory()->admin()->create()->can('view', $balance));

        $this->exchange->delete();

        $this->assertModelMissing($balance);
    }

    // The command

    public function test_the_command_syncs_every_active_exchange_of_an_active_user(): void
    {
        $this->fakeBalances();
        $inactive = Exchange::factory()->inactive()->create();
        $ofInactiveUser = Exchange::factory()->for(User::factory()->inactive())->create();
        $second = Exchange::factory()->create();

        $this->artisan('nugget:sync-exchanges')->expectsOutputToContain('Synced 2 of 2 exchanges.')->assertSuccessful();

        $this->assertNotNull($this->exchange->refresh()->last_synced_at);
        $this->assertNotNull($second->refresh()->last_synced_at);
        $this->assertNull($inactive->refresh()->last_synced_at);
        $this->assertNull($ofInactiveUser->refresh()->last_synced_at);
    }

    public function test_a_failing_exchange_does_not_stop_the_others(): void
    {
        Http::fakeSequence(self::BALANCES)
            ->push('{"code":"InvalidAPIKey","message":"invalid api key"}', 401)
            ->push('[{"assetName":"AUD","balance":"75","available":"75","locked":"0"}]');
        $second = Exchange::factory()->create();

        $this->artisan('nugget:sync-exchanges')->expectsOutputToContain('Synced 1 of 2 exchanges. 1 failed')->assertSuccessful();

        $this->assertNotNull($this->exchange->refresh()->last_error);
        $this->assertSame('75.00', $second->refresh()->aud_balance->toFixed(2));
    }

    public function test_the_command_fails_when_no_exchange_syncs(): void
    {
        $this->fakeBalances('{"code":"InvalidAPIKey","message":"invalid api key"}', 401);

        $this->artisan('nugget:sync-exchanges')->assertFailed();
    }

    public function test_the_command_syncs_one_exchange_by_id_active_or_not(): void
    {
        $this->fakeBalances();
        $inactive = Exchange::factory()->inactive()->create();

        $this->artisan('nugget:sync-exchanges', ['--exchange' => $inactive->id])->expectsOutputToContain('Synced 1 of 1 exchanges.')->assertSuccessful();

        $this->assertNotNull($inactive->refresh()->last_synced_at);
        $this->assertNull($this->exchange->refresh()->last_synced_at);

        $this->artisan('nugget:sync-exchanges', ['--exchange' => 9999])->expectsOutputToContain('There is no exchange with the id 9999.')->assertFailed();
    }

    public function test_with_no_exchanges_the_command_has_nothing_to_do(): void
    {
        Exchange::query()->delete();

        $this->artisan('nugget:sync-exchanges')->expectsOutputToContain('Synced 0 of 0 exchanges.')->assertSuccessful();
    }
}
