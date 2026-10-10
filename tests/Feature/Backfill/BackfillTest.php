<?php

namespace Tests\Feature\Backfill;

use App\Backfill\Backfill;
use App\Backfill\BackfillResult;
use App\Backfill\BtcMarketsCsv;
use App\Enums\TaxSection;
use App\Enums\TransactionSource;
use App\Facades\Settings;
use App\Ledger\Holdings;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FinancialYear;
use App\Tax\CgtCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backfilling an exchange from the made-up transaction report, for a user in
 * Melbourne who already has Bitcoin and Cardano on the exchange, each with an
 * estimated ledger from the tkbtc import.
 */
class BackfillTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT = 'tests/Fixtures/btcmarkets/transaction-report.csv';

    private User $user;

    private Exchange $exchange;

    private Asset $bitcoin;

    private Asset $cardano;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->exchange = Exchange::factory()->for($this->user)->create(['label' => 'BTC Markets']);
        $this->bitcoin = Asset::factory()->onExchange($this->exchange, 'BTC-AUD')->create(['name' => 'Bitcoin', 'symbol' => 'BTC', 'unit' => 'BTC']);
        $this->cardano = Asset::factory()->onExchange($this->exchange, 'ADA-AUD')->create(['name' => 'Cardano', 'symbol' => 'ADA', 'unit' => 'ADA']);

        // What the tkbtc import guessed: balances rising, costed at a tick.
        $this->estimate($this->bitcoin, '2024-07-02 01:05:00', '0.1', '99000');
        $this->estimate($this->cardano, '2024-07-03 02:10:00', '1000', '0.51');
        app(ParcelMatcher::class)->rematch($this->bitcoin);
        app(ParcelMatcher::class)->rematch($this->cardano);

        // The price of a bitcoin on 24 December 2024: $150,500 at the middle.
        Price::factory()->for($this->bitcoin)->day('2024-12-24')->quoted('150000', '151000')->create();
    }

    private function estimate(Asset $asset, string $at, string $units, string $price): Transaction
    {
        return Transaction::factory()->for($asset)->buy($units, $price)->estimated()->create([
            'occurred_at' => $at, 'source' => TransactionSource::Import, 'external_id' => 'tkbtc-log-'.$asset->id.'-'.strtotime($at),
        ]);
    }

    private function backfill(bool $dryRun = false, ?Exchange $exchange = null): BackfillResult
    {
        return app(Backfill::class)->run($exchange ?? $this->exchange, BtcMarketsCsv::read(fopen(base_path(self::REPORT), 'r')), $dryRun);
    }

    private function asset(string $symbol): Asset
    {
        return $this->exchange->assets()->where('symbol', $symbol)->sole();
    }

    /**
     * An asset's transactions, oldest first, as "type units @ price fee total".
     *
     * @return list<string>
     */
    private function ledger(Asset $asset): array
    {
        return $asset->transactions()->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn (Transaction $t) => "{$t->type->value} {$t->units} @ {$t->unit_price} {$t->fee->toFixed(2)} {$t->total->toFixed(2)}".($t->estimated ? ' estimated' : ''))
            ->all();
    }

    public function test_the_estimates_are_replaced_by_the_reports_own_trades(): void
    {
        $result = $this->backfill();

        $this->assertSame([
            'buy 0.1 @ 100000 85.00 10085.00',
            'sell 0.02 @ 110000 18.70 2181.30',
            // Bought with 200 XRP, worth $298.44 at that day's bitcoin price.
            'buy 0.001983 @ 150499.24357035 0.00 298.44',
            // Sold for 99.15 XRP: no price that day, so the nearest bitcoin trade in the report, $120,000.
            'sell 0.001 @ 120000 0.00 120.00 estimated',
            'transfer_out 0.03 @ 0 0.00 0.00',
            'transfer_in 0.01 @ 0 0.00 0.00',
            'buy 0.05 @ 120000 0.00 6000.00',
        ], $this->ledger($this->bitcoin));

        $this->assertSame([
            'buy 400 @ 0.5 1.70 201.70',
            'buy 600 @ 0.5 2.55 302.55',
        ], $this->ledger($this->cardano));

        $this->assertSame(2, $result->estimatesRemoved);
        $this->assertSame(['buy' => 6, 'sell' => 3, 'transfer_in' => 2, 'transfer_out' => 1, 'reward' => 2], $result->added);
        $this->assertSame(14, $result->total());
        // Both sides of the second swap.
        $this->assertSame(2, $result->valuedFromTheReport);
        $this->assertSame([], $result->warnings);
    }

    public function test_a_backfilled_transaction_is_the_exchanges_own_and_keyed_by_the_reports_id(): void
    {
        $this->backfill();

        $buy = $this->bitcoin->transactions()->oldest('occurred_at')->first();

        $this->assertSame(TransactionSource::Exchange, $buy->source);
        $this->assertSame('csv-900000003', $buy->external_id);
        $this->assertTrue($buy->isLocked());
        $this->assertFalse($buy->estimated);
        $this->assertSame('2024-07-02 01:00:00', $buy->occurred_at->toDateTimeString());
    }

    public function test_a_coin_swapped_for_a_coin_is_a_sale_of_one_and_a_purchase_of_the_other_at_the_same_aud_value(): void
    {
        $this->backfill();

        $ripple = $this->asset('XRP');

        $this->assertSame([
            'transfer_in 500 @ 0 0.00 0.00 estimated',
            'sell 200 @ 1.4922 0.00 298.44',
            'buy 99.15 @ 1.21028744 0.00 120.00 estimated',
        ], $this->ledger($ripple));

        $sale = $ripple->transactions()->where('type', 'sell')->sole();
        $this->assertSame('Sold for 0.001983 BTC, valued in AUD at the BTC price of the day.', $sale->notes);
        $this->assertStringContainsString('valued in AUD at the nearest BTC trade in the report', $ripple->transactions()->where('type', 'buy')->sole()->notes);
    }

    public function test_assets_the_report_has_and_nugget_does_not_are_made_on_the_exchange(): void
    {
        $result = $this->backfill();

        $this->assertSame(['ALGO', 'ETHW', 'XRP'], $result->assetsCreated);

        $ripple = $this->asset('XRP');
        $this->assertSame([$this->user->id, 'XRP', 'XRP', 'crypto', 'XRP-AUD', false], [
            $ripple->user_id, $ripple->name, $ripple->unit, $ripple->class->value, $ripple->market_id, $ripple->archived,
        ]);
        $this->assertSame(5, $this->exchange->assets()->count());
    }

    public function test_a_deposit_with_nothing_withdrawn_before_it_has_no_cost_and_is_listed(): void
    {
        $result = $this->backfill();

        // The 500 XRP came from elsewhere. The 0.01 BTC came back from the 0.03 withdrawn, with its cost.
        $this->assertSame(['5 Aug 2024: 500 XRP of XRP'], $result->depositsWithoutCost);

        $deposit = $this->asset('XRP')->transactions()->where('type', 'transfer_in')->sole();
        $this->assertTrue($deposit->estimated);
        $this->assertStringContainsString('put the real cost on this', $deposit->notes);

        $back = $this->bitcoin->transactions()->where('type', 'transfer_in')->sole();
        $this->assertFalse($back->estimated);
        $this->assertTrue($back->parcels()->get()->every(fn ($parcel) => $parcel->cost_base->isPositive()));
    }

    public function test_a_reward_with_no_price_is_at_nothing_and_estimated_and_an_airdrop_is_at_nothing_on_purpose(): void
    {
        $result = $this->backfill();

        $this->assertSame(['reward 2.5 @ 0 0.00 0.00 estimated'], $this->ledger($this->asset('ALGO')));
        $this->assertSame(['reward 0.5 @ 0 0.00 0.00'], $this->ledger($this->asset('ETHW')));
        $this->assertSame('An airdrop: it came at no cost.', $this->asset('ETHW')->transactions()->sole()->notes);
        $this->assertSame(1, $result->notValued);
    }

    public function test_a_reward_is_costed_at_the_price_of_the_day_when_there_is_one(): void
    {
        $algo = Asset::factory()->onExchange($this->exchange, 'ALGO-AUD')->create(['name' => 'Algorand', 'symbol' => 'ALGO', 'unit' => 'ALGO']);
        Price::factory()->for($algo)->day('2025-03-01')->quoted('0.40', '0.42')->create();

        $result = $this->backfill();

        // 2.5 at $0.41.
        $this->assertSame(['reward 2.5 @ 0.412 0.00 1.03'], $this->ledger($algo));
        $this->assertSame(0, $result->notValued);
        $this->assertNotContains('ALGO', $result->assetsCreated);
    }

    public function test_the_parcels_are_matched_and_the_units_agree_with_the_reports_balances(): void
    {
        $result = $this->backfill();
        $holdings = app(Holdings::class);

        $this->assertSame('0.110983', (string) $holdings->forAsset($this->bitcoin)->units);
        $this->assertSame('1000', (string) $holdings->forAsset($this->cardano)->units);
        $this->assertSame('399.15', (string) $holdings->forAsset($this->asset('XRP'))->units);
        $this->assertSame('504.25', $holdings->forAsset($this->cardano)->costBase->toFixed(2));

        // Before: what the estimates gave. After: the report.
        $this->assertSame(['before' => ['0.1', '9900.00'], 'after' => ['0.110983', $holdings->forAsset($this->bitcoin)->costBase->toFixed(2)]], $result->summary['Bitcoin']);
        $this->assertSame(['before' => ['1000', '510.00'], 'after' => ['1000', '504.25']], $result->summary['Cardano']);
        $this->assertSame(['before' => ['0', '0.00'], 'after' => ['399.15', '120.00']], $result->summary['XRP']);
    }

    public function test_the_tax_report_then_has_the_real_disposals(): void
    {
        Settings::set('first_report_year', 2025);
        $this->backfill();

        $report = app(CgtCalculator::class)->forYear($this->user, new FinancialYear(2025));
        $crypto = $report->summary->sections[0];

        // The bitcoin sold in August and December, and the XRP swapped away in December.
        $this->assertSame(3, $crypto->disposals);
        // The XRP had no cost, and one sale was valued from the report: both rest on estimates.
        $this->assertSame(1, $crypto->estimated);
        // 0.02 of the first bitcoin: $2,181.30 less a fifth of $10,085.
        $this->assertSame('164.30', $report->disposals(TaxSection::Crypto)->first()->gain->toFixed(2));
    }

    public function test_running_it_again_adds_nothing(): void
    {
        $this->backfill();
        $before = DB::table('transactions')->orderBy('id')->pluck('id')->all();

        $again = $this->backfill();

        $this->assertSame([], $again->added);
        $this->assertSame(14, $again->alreadyThere);
        $this->assertSame(0, $again->estimatesRemoved);
        $this->assertSame([], $again->assetsCreated);
        $this->assertSame($before, DB::table('transactions')->orderBy('id')->pluck('id')->all());
        $this->assertSame('0.110983', (string) app(Holdings::class)->forAsset($this->bitcoin)->units);
    }

    public function test_every_estimate_of_the_reports_assets_goes_and_nothing_else(): void
    {
        // After the report ends: the trade sync will bring the real one, so the guess goes too.
        $later = $this->estimate($this->bitcoin, '2025-06-01 02:00:00', '0.2', '130000');
        // Entered by hand, and on an asset that is not on the exchange.
        $mine = Transaction::factory()->for($this->cardano)->buy('5', '1')->create(['occurred_at' => '2025-06-02 02:00:00']);
        $wallet = Asset::factory()->for($this->user)->create(['symbol' => 'BTC', 'unit' => 'BTC', 'name' => 'Cold wallet']);
        $elsewhere = $this->estimate($wallet, '2024-07-02 01:05:00', '1', '99000');

        $result = $this->backfill();

        $this->assertSame(3, $result->estimatesRemoved);
        $this->assertModelMissing($later);
        $this->assertModelExists($mine);
        $this->assertModelExists($elsewhere);
        $this->assertSame('0.110983', (string) app(Holdings::class)->forAsset($this->bitcoin)->units);
    }

    public function test_trades_the_sync_fetched_inside_the_reports_time_are_removed_as_duplicates(): void
    {
        $synced = Transaction::factory()->for($this->bitcoin)->buy('0.1', '100000', '85')->fromExchange('5551234567')->create(['occurred_at' => '2024-07-02 01:00:00']);
        $after = Transaction::factory()->for($this->bitcoin)->buy('0.3', '125000')->fromExchange('5559999999')->create(['occurred_at' => '2025-05-01 01:00:00']);

        $result = $this->backfill();

        $this->assertSame(1, $result->syncedRemoved);
        $this->assertModelMissing($synced);
        $this->assertModelExists($after);
        $this->assertSame('0.410983', (string) app(Holdings::class)->forAsset($this->bitcoin)->units);
    }

    public function test_the_trade_sync_is_told_to_carry_on_from_the_end_of_the_report(): void
    {
        $this->backfill();

        $this->assertSame('2025-04-01 08:00:00', $this->exchange->refresh()->trades_after->toDateTimeString());

        // An older report does not move it back.
        $this->exchange->forceFill(['trades_after' => '2026-01-01 00:00:00'])->save();
        $this->backfill();

        $this->assertSame('2026-01-01 00:00:00', $this->exchange->refresh()->trades_after->toDateTimeString());
    }

    public function test_a_ledger_that_disagrees_with_the_reports_balance_is_reported(): void
    {
        // Entered by hand inside the report's time, which the report knows nothing of.
        Transaction::factory()->for($this->cardano)->buy('5', '1')->create(['occurred_at' => '2024-09-01 02:00:00']);

        $result = $this->backfill();

        $this->assertSame(["Cardano: at the end of the report the ledger holds 1005 ADA and the report's balance is 1000."], $result->warnings);
    }

    public function test_a_coin_no_longer_held_is_made_archived(): void
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, implode("\n", [
            'transactionId, creationTime, recordType, action, currency, amount, description, balance, referenceId',
            '1,2024-07-02T01:00:00Z,Trade,Buy Order,AUD,-100,Buy 1LTC @ AUD 100 Fully matched,-100,R1',
            '2,2024-07-02T01:00:00Z,Trade,Buy Order,LTC,1,Buy 1LTC @ AUD 100 Trade settled,1,R1',
            '3,2024-08-02T01:00:00Z,Trade,Sell Order,LTC,-1,Sell 1LTC @ AUD 150 Trade settled,0,R2',
            '4,2024-08-02T01:00:00Z,Trade,Sell Order,AUD,150,Sell 1LTC @ AUD 150 Fully matched,50,R2',
        ])."\n");
        rewind($stream);

        $result = app(Backfill::class)->run($this->exchange, BtcMarketsCsv::read($stream));

        $this->assertSame(['LTC (archived: none is held now)'], $result->assetsCreated);
        $this->assertTrue($this->asset('LTC')->archived);
        $this->assertSame(['buy 1 @ 100 0.00 100.00', 'sell 1 @ 150 0.00 150.00'], $this->ledger($this->asset('LTC')));
        // Nothing of the other assets is touched: the report does not mention them.
        $this->assertSame(0, $result->estimatesRemoved);
    }

    public function test_a_dry_run_reports_the_same_and_changes_nothing(): void
    {
        $before = [DB::table('transactions')->count(), DB::table('assets')->count(), DB::table('parcels')->count()];

        $result = $this->backfill(dryRun: true);

        $this->assertSame(14, $result->total());
        $this->assertSame(2, $result->estimatesRemoved);
        $this->assertSame(['ALGO', 'ETHW', 'XRP'], $result->assetsCreated);
        $this->assertSame($before, [DB::table('transactions')->count(), DB::table('assets')->count(), DB::table('parcels')->count()]);
        $this->assertNull($this->exchange->refresh()->trades_after);
        $this->assertSame('0.1', (string) app(Holdings::class)->forAsset($this->bitcoin)->units);
    }

    // The command

    public function test_the_command_backfills_and_prints_a_before_and_after(): void
    {
        $this->artisan('nugget:backfill', ['exchange' => $this->exchange->id, '--csv' => base_path(self::REPORT)])
            ->expectsOutputToContain('30 rows, 2024-07-01 to 2025-04-01 (UTC).')
            ->expectsOutputToContain('Estimated transactions replaced: 2')
            ->expectsOutputToContain('Assets made: ALGO, ETHW, XRP')
            ->expectsOutputToContain('1 deposit has no cost known.')
            ->expectsOutputToContain('5 Aug 2024: 500 XRP of XRP')
            ->expectsOutputToContain('Backfill finished.')
            ->assertSuccessful();

        $this->assertSame(7, $this->bitcoin->transactions()->count());
    }

    public function test_the_commands_dry_run_changes_nothing(): void
    {
        $this->artisan('nugget:backfill', ['exchange' => $this->exchange->id, '--csv' => base_path(self::REPORT), '--dry-run' => true])
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(1, $this->bitcoin->transactions()->count());
    }

    public function test_the_command_refuses_an_exchange_or_a_file_it_cannot_use(): void
    {
        $other = Exchange::factory()->for($this->user)->create(['driver' => 'kraken', 'label' => 'Kraken']);

        $this->artisan('nugget:backfill', ['exchange' => 99999, '--csv' => base_path(self::REPORT)])
            ->expectsOutputToContain('There is no exchange with the id 99999.')->assertFailed();
        $this->artisan('nugget:backfill', ['exchange' => $other->id, '--csv' => base_path(self::REPORT)])
            ->expectsOutputToContain('Only a BTC Markets transaction report can be read.')->assertFailed();
        $this->artisan('nugget:backfill', ['exchange' => $this->exchange->id])
            ->expectsOutputToContain('Pass the report: --csv=')->assertFailed();
        $this->artisan('nugget:backfill', ['exchange' => $this->exchange->id, '--csv' => '/nowhere/report.csv'])
            ->expectsOutputToContain('could not be opened')->assertFailed();
        $this->artisan('nugget:backfill', ['exchange' => $this->exchange->id, '--csv' => base_path('composer.json')])
            ->expectsOutputToContain('The backfill failed and nothing was changed: This is not a BTC Markets transaction report')->assertFailed();

        $this->assertSame(1, $this->bitcoin->transactions()->count());
    }
}
