<?php

namespace Tests\Feature\Import;

use App\Enums\TransactionSource;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Passes\AssetLogPass;
use App\Import\TkbtcSource;
use App\Ledger\Holdings;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * tkbtc's balance log turned into estimated transactions. The made-up tkbtc
 * database has Bitcoin on an exchange (asset 12, market 1) and gold held by
 * hand (asset 20, market 16), both already imported as assets.
 */
class AssetLogPassTest extends TestCase
{
    use RefreshDatabase;
    use TkbtcDatabase;

    private Asset $bitcoin;

    private Asset $gold;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTkbtc();
        $user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $exchange = Exchange::factory()->for($user)->create();
        $this->bitcoin = Asset::factory()->onExchange($exchange, 'BTC-AUD')->create(['name' => 'Bitcoin', 'symbol' => 'BTC', 'unit' => 'BTC']);
        $this->gold = Asset::factory()->for($user)->metal()->create(['name' => 'Gold', 'symbol' => 'XAU', 'unit' => 'oz']);

        $this->tkbtc()->table('market')->insert([
            ['market_id' => 1, 'user_id' => 2, 'exchange_id' => 1, 'name' => 'Bitcoin', 'api_id' => 'BTC-AUD', 'symbol' => 'BTC/AUD', 'base' => 'BTC'],
            ['market_id' => 16, 'user_id' => 2, 'exchange_id' => null, 'name' => 'Gold', 'api_id' => '', 'symbol' => 'XAU/AUD', 'base' => 'XAU'],
        ]);
        $this->tkbtc()->table('asset')->insert([
            ['asset_id' => 12, 'market_id' => 1, 'user_id' => 2, 'units' => '0'],
            ['asset_id' => 20, 'market_id' => 16, 'user_id' => 2, 'units' => '0'],
        ]);

        $context = $this->context();
        $context->map('user', 2, $user->id);
        $context->map('exchange', 1, $exchange->id);
        $context->map('asset', 12, $this->bitcoin->id);
        $context->map('asset', 20, $this->gold->id);
    }

    private function context(): ImportContext
    {
        return new ImportContext(app(TkbtcSource::class));
    }

    private function import(bool $fresh = false): ImportContext
    {
        $context = $this->context();
        (new Importer([app(AssetLogPass::class)]))->run($context, $fresh);

        return $context;
    }

    /**
     * A change in a tkbtc asset's balance. The asset's units become the running total.
     */
    private function change(int $assetId, string $at, string $units): void
    {
        $total = bcadd((string) $this->tkbtc()->table('asset')->where('asset_id', $assetId)->value('units'), $units, 8);

        $this->tkbtc()->table('asset_log')->insert(['asset_id' => $assetId, 'units' => $units, 'total' => $total, 'created' => $at]);
        $this->tkbtc()->table('asset')->where('asset_id', $assetId)->update(['units' => $total]);
    }

    private function tick(int $marketId, string $at, string $bid, string $ask): void
    {
        $this->tkbtc()->table('tick')->insert(['market_id' => $marketId, 'created' => $at, 'bid' => $bid, 'ask' => $ask]);
    }

    /**
     * An asset's transactions, oldest first, as "type units @ price total".
     *
     * @return list<string>
     */
    private function ledger(Asset $asset): array
    {
        return $asset->transactions()->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn (Transaction $t) => "{$t->type->value} {$t->units} @ {$t->unit_price} {$t->total->toFixed(2)}")
            ->all();
    }

    public function test_a_rise_in_the_balance_is_an_estimated_buy_and_a_fall_an_estimated_sell(): void
    {
        $this->tick(1, '2024-08-01 02:00:00', '99000', '100000');
        $this->tick(1, '2024-11-01 02:00:00', '120000', '121000');
        $this->change(12, '2024-08-01 02:00:00', '0.50000000');
        $this->change(12, '2024-11-01 02:00:00', '-0.20000000');

        $context = $this->import();

        // Bought at the ask, sold at the bid.
        $this->assertSame(['buy 0.5 @ 100000 50000.00', 'sell 0.2 @ 120000 24000.00'], $this->ledger($this->bitcoin));

        $buy = $this->bitcoin->transactions()->oldest('occurred_at')->first();
        $this->assertTrue($buy->estimated);
        $this->assertSame(TransactionSource::Import, $buy->source);
        $this->assertSame('tkbtc-log-12-20240801020000', $buy->external_id);
        $this->assertSame('2024-08-01 02:00:00', $buy->occurred_at->toDateTimeString());
        $this->assertTrue($buy->fee->isZero());
        $this->assertStringContainsString('Imported from tkbtc', $buy->notes);
        $this->assertFalse($buy->isLocked());
        $this->assertSame([], $context->warnings);
    }

    public function test_the_cost_is_taken_from_the_tick_nearest_in_time(): void
    {
        $this->tick(1, '2024-08-01 02:00:00', '100', '101');
        $this->tick(1, '2024-08-01 02:10:00', '200', '201');
        $this->tick(1, '2024-08-01 02:20:00', '300', '301');

        // Four minutes after the first tick, six before the second: the first.
        $this->change(12, '2024-08-01 02:04:00', '1');
        // Six minutes after the second, four before the third: the third.
        $this->change(12, '2024-08-01 02:16:00', '1');
        // Before the first tick and after the last: the only one on that side.
        $this->change(12, '2024-07-01 02:00:00', '1');
        $this->change(12, '2024-09-01 02:00:00', '-1');
        // Exactly between two: the earlier.
        $this->change(12, '2024-08-01 02:05:00', '1');

        $this->import();

        $this->assertSame([
            'buy 1 @ 101 101.00',
            'buy 1 @ 101 101.00',
            'buy 1 @ 101 101.00',
            'buy 1 @ 301 301.00',
            'sell 1 @ 300 300.00',
        ], $this->ledger($this->bitcoin));
    }

    public function test_another_markets_ticks_are_not_used(): void
    {
        $this->tick(16, '2024-08-01 02:00:00', '4000', '4010');
        $this->tick(1, '2024-08-03 02:00:00', '100', '101');
        $this->change(12, '2024-08-01 02:00:00', '1');

        $this->import();

        $this->assertSame(['buy 1 @ 101 101.00'], $this->ledger($this->bitcoin));
    }

    public function test_a_rise_in_a_manual_assets_balance_is_found_at_no_cost(): void
    {
        $this->tick(16, '2025-07-05 22:10:00', '5000', '5010');
        $this->change(20, '2025-07-05 22:02:09', '3.00000000');
        $this->change(20, '2026-01-06 04:43:38', '-1.00000000');

        $this->import();

        // The fall is still a sale, at the bid.
        $this->assertSame(['found 3 @ 0 0.00', 'sell 1 @ 5000 5000.00'], $this->ledger($this->gold));

        $found = $this->gold->transactions()->oldest('occurred_at')->first();
        $this->assertTrue($found->estimated);
        $this->assertStringContainsString('Put the real cost on this.', $found->notes);

        $holding = app(Holdings::class)->forAsset($this->gold);
        $this->assertSame(['2', '0.00'], [(string) $holding->units, $holding->costBase->toFixed(2)]);
        $this->assertTrue($holding->estimated);
    }

    public function test_a_change_with_no_tick_to_cost_it_is_imported_at_nothing_and_warned_about(): void
    {
        $this->change(12, '2024-08-01 02:00:00', '1');

        $context = $this->import();

        $this->assertSame(['buy 1 @ 0 0.00'], $this->ledger($this->bitcoin));
        $this->assertSame(['Bitcoin: the change of 1 on 2024-08-01 has no tick to cost it with, so it was imported at $0.'], $context->warnings);
    }

    public function test_the_parcels_are_matched_and_the_units_held_agree_with_tkbtc(): void
    {
        $this->tick(1, '2024-08-01 02:00:00', '99000', '100000');
        $this->change(12, '2024-08-01 02:00:00', '0.5');
        $this->change(12, '2024-09-01 02:00:00', '0.25');
        $this->change(12, '2024-11-01 02:00:00', '-0.6');
        $this->change(20, '2025-07-05 22:02:09', '3');

        $context = $this->import();

        $bitcoin = app(Holdings::class)->forAsset($this->bitcoin);
        // 0.15 left of the second purchase of 0.25 at $100,000.
        $this->assertSame(['0.15', '15000.00', 1], [(string) $bitcoin->units, $bitcoin->costBase->toFixed(2), $bitcoin->openParcels]);
        $this->assertSame(2, $this->bitcoin->transactions()->where('type', 'sell')->sole()->disposals()->count());
        $this->assertSame([], $context->warnings);
        $this->assertSame(['Units held agree with tkbtc for 2 of 2 assets.'], $context->notes);
    }

    public function test_a_holding_that_differs_from_tkbtcs_figure_is_reported(): void
    {
        $this->tick(1, '2024-08-01 02:00:00', '99000', '100000');
        $this->change(12, '2024-08-01 02:00:00', '0.5');
        // tkbtc's own figure was changed without a log row.
        $this->tkbtc()->table('asset')->where('asset_id', 12)->update(['units' => '0.75000000']);

        $context = $this->import();

        $this->assertSame(['Bitcoin: the ledger holds 0.5 BTC and tkbtc has 0.75.'], $context->warnings);
        $this->assertSame(['Units held agree with tkbtc for 1 of 2 assets.'], $context->notes);
    }

    public function test_a_change_of_nothing_and_an_asset_that_was_not_imported_are_passed_over(): void
    {
        $this->tick(1, '2024-08-01 02:00:00', '99000', '100000');
        $this->change(12, '2024-08-01 02:00:00', '0.00000000');
        $this->tkbtc()->table('market')->insert(['market_id' => 3, 'user_id' => 2, 'exchange_id' => 1, 'name' => 'Cardano', 'api_id' => 'ADA-AUD', 'symbol' => 'ADA/AUD', 'base' => 'ADA']);
        $this->tkbtc()->table('asset')->insert(['asset_id' => 1, 'market_id' => 3, 'user_id' => 2, 'units' => '0']);
        $this->change(1, '2024-08-01 02:00:00', '500');

        $context = $this->import();

        $this->assertSame(0, Transaction::count());
        $this->assertSame(['Units held agree with tkbtc for 2 of 2 assets.'], $context->notes);
    }

    public function test_running_again_adds_nothing_twice_and_brings_over_a_new_change(): void
    {
        $this->tick(1, '2024-08-01 02:00:00', '99000', '100000');
        $this->change(12, '2024-08-01 02:00:00', '0.5');
        $this->import();
        // The owner has since put the real cost on it.
        $this->bitcoin->transactions()->sole()->update(['unit_price' => '98765', 'total' => '49382.50', 'estimated' => false]);

        $this->change(12, '2024-09-01 02:00:00', '0.25');
        $this->import();
        $this->import();

        $this->assertSame(['buy 0.5 @ 98765 49382.50', 'buy 0.25 @ 100000 25000.00'], $this->ledger($this->bitcoin));
        $this->assertSame('0.75', (string) app(Holdings::class)->forAsset($this->bitcoin)->units);
    }

    public function test_a_fresh_run_replaces_the_imported_transactions_and_keeps_the_owners_own(): void
    {
        $this->tick(1, '2024-08-01 02:00:00', '99000', '100000');
        $this->change(12, '2024-08-01 02:00:00', '0.5');
        $this->import();
        $imported = $this->bitcoin->transactions()->sole();
        $mine = Transaction::factory()->for($this->bitcoin)->buy('1', '90000')->create(['occurred_at' => '2024-07-01 02:00:00']);

        $this->import(fresh: true);

        $this->assertModelMissing($imported);
        $this->assertModelExists($mine);
        $this->assertSame(['buy 1 @ 90000 90000.00', 'buy 0.5 @ 100000 50000.00'], $this->ledger($this->bitcoin));
        $this->assertSame('1.5', (string) app(Holdings::class)->forAsset($this->bitcoin)->units);
    }

    public function test_the_count_is_the_rows_of_the_log(): void
    {
        $this->change(12, '2024-08-01 02:00:00', '0.5');
        $this->change(20, '2025-07-05 22:02:09', '3');

        $this->assertSame(2, app(AssetLogPass::class)->count($this->context()));
    }
}
