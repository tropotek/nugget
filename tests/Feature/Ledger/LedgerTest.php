<?php

namespace Tests\Feature\Ledger;

use App\Enums\ParcelMatching;
use App\Facades\Settings;
use App\Ledger\InsufficientUnits;
use App\Ledger\InvalidParcelPick;
use App\Ledger\Ledger;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Parcel;
use App\Models\ParcelPick;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Changing the ledger through App\Ledger\Ledger. Times are 02:00 UTC, early
 * afternoon on the same day in Melbourne, the owner's timezone.
 */
class LedgerTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    private Ledger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::factory()->create(['name' => 'Bitcoin', 'unit' => 'BTC']);
        $this->ledger = app(Ledger::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function buy(string $day, string $units, string $price): array
    {
        return Transaction::factory()->buy($units, $price)->raw(['asset_id' => null, 'occurred_at' => "{$day} 02:00:00"]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sell(string $day, string $units, string $price): array
    {
        return Transaction::factory()->sell($units, $price)->raw(['asset_id' => null, 'occurred_at' => "{$day} 02:00:00"]);
    }

    /**
     * The parcel a buy opened.
     */
    private function parcelOf(Transaction $buy): Parcel
    {
        return $buy->parcels()->sole();
    }

    /**
     * A sale's disposals as [parcel cost base, units], oldest parcel first.
     *
     * @return list<array{string, string}>
     */
    private function used(Transaction $sale): array
    {
        return $sale->disposals()->get()
            ->sortBy(fn (Disposal $d) => $d->parcel->acquired_at)
            ->map(fn (Disposal $d) => [$d->parcel->cost_base->toFixed(2), (string) $d->units])
            ->values()->all();
    }

    // Recording, amending and removing

    public function test_recording_a_buy_saves_it_and_opens_its_parcel(): void
    {
        $buy = $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));

        $this->assertTrue($buy->exists);
        $this->assertTrue($buy->asset->is($this->asset));
        $this->assertSame('100.00', $this->parcelOf($buy)->cost_base->toFixed(2));
    }

    public function test_recording_a_sale_writes_its_disposals(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '0.4', '200'));

        $this->assertSame([['100.00', '0.4']], $this->used($sale));
    }

    public function test_recording_an_earlier_buy_rematches_the_sales_after_it(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-02-10', '1', '300'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $this->assertSame([['300.00', '1']], $this->used($sale));

        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));

        $this->assertSame([['100.00', '1']], $this->used($sale));
    }

    public function test_recording_a_sale_of_more_than_is_held_is_refused_and_not_saved(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));

        try {
            $this->ledger->record($this->asset, $this->sell('2024-06-01', '1.5', '200'));
            $this->fail('The sale was not refused.');
        } catch (InsufficientUnits) {
        }

        $this->assertSame(1, Transaction::count());
        $this->assertSame('1', (string) Parcel::sole()->units_remaining);
    }

    public function test_amending_a_transaction_rematches(): void
    {
        $buy = $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));

        $this->ledger->amend($buy, ['total' => '250']);

        $this->assertSame('250.00', $buy->fresh()->total->toFixed(2));
        $this->assertSame('150.00', $sale->disposals()->sole()->gain->toFixed(2));
    }

    public function test_an_amendment_that_leaves_a_later_sale_short_is_refused_and_nothing_changes(): void
    {
        $buy = $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $disposalId = $sale->disposals()->sole()->id;

        try {
            $this->ledger->amend($buy, ['units' => '0.5']);
            $this->fail('The amendment was not refused.');
        } catch (InsufficientUnits) {
        }

        $this->assertSame('1', (string) $buy->fresh()->units);
        $this->assertSame($disposalId, $sale->disposals()->sole()->id);
    }

    public function test_removing_a_sale_gives_its_units_back_to_the_parcel(): void
    {
        $buy = $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '0.4', '200'));

        $this->ledger->remove($sale);

        $this->assertNull($sale->fresh());
        $this->assertSame(0, Disposal::count());
        $this->assertSame('1', (string) $this->parcelOf($buy)->units_remaining);
    }

    public function test_removing_a_buy_removes_its_parcel(): void
    {
        $kept = $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $buy = $this->ledger->record($this->asset, $this->buy('2024-02-10', '1', '300'));

        $this->ledger->remove($buy);

        $this->assertNull($buy->fresh());
        $this->assertTrue(Parcel::sole()->transaction->is($kept));
    }

    public function test_removing_a_buy_that_a_sale_needs_is_refused_and_nothing_changes(): void
    {
        $buy = $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));

        try {
            $this->ledger->remove($buy);
            $this->fail('The removal was not refused.');
        } catch (InsufficientUnits) {
        }

        $this->assertNotNull(Transaction::find($buy->id));
        $this->assertSame([['100.00', '1']], $this->used($sale));
    }

    public function test_a_change_to_one_holding_rematches_the_holdings_it_transfers_with(): void
    {
        $wallet = Asset::factory()->for($this->asset->user)->create();
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $this->ledger->record($this->asset, Transaction::factory()->transferOut('0.4')->raw(['asset_id' => null, 'occurred_at' => '2024-06-01 02:00:00']));
        $this->ledger->record($wallet, Transaction::factory()->transferIn('0.4')->raw(['asset_id' => null, 'occurred_at' => '2024-06-02 02:00:00']));

        $this->assertSame('40.00', $wallet->parcels()->sole()->cost_base->toFixed(2));
    }

    // The matching method

    public function test_a_sale_remembers_the_method_it_was_matched_by(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '0.5', '400'));

        $this->assertSame(ParcelMatching::Fifo, $sale->fresh()->matching);
        $this->assertNull(Transaction::where('type', 'buy')->sole()->matching);
    }

    public function test_changing_the_setting_does_not_rewrite_past_sales(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $this->ledger->record($this->asset, $this->buy('2024-02-10', '1', '300'));
        $past = $this->ledger->record($this->asset, $this->sell('2024-06-01', '0.5', '400'));

        Settings::set('parcel_matching', 'lowest_gain');
        $later = $this->ledger->record($this->asset, $this->sell('2024-07-01', '0.5', '400'));

        $this->assertSame([['100.00', '0.5']], $this->used($past));
        $this->assertSame(ParcelMatching::Fifo, $past->fresh()->matching);

        $this->assertSame([['300.00', '0.5']], $this->used($later));
        $this->assertSame(ParcelMatching::LowestGain, $later->fresh()->matching);
    }

    // Hand-picked parcels

    public function test_a_sale_can_be_matched_by_hand(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $b = $this->ledger->record($this->asset, $this->buy('2024-02-10', '1', '300'));
        $c = $this->ledger->record($this->asset, $this->buy('2024-03-10', '1', '200'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1.5', '400'));

        $this->ledger->pick($sale, [
            ['parcel' => $this->parcelOf($b), 'units' => '1'],
            ['parcel' => $this->parcelOf($c), 'units' => '0.5'],
        ]);

        $this->assertSame([['300.00', '1'], ['200.00', '0.5']], $this->used($sale));
        $this->assertSame(['1', '0', '0.5'], Parcel::orderBy('acquired_at')->get()->map(fn (Parcel $p) => (string) $p->units_remaining)->all());
        $this->assertTrue($sale->fresh()->isHandMatched());
    }

    public function test_hand_picked_parcels_are_kept_through_a_rematch(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $b = $this->ledger->record($this->asset, $this->buy('2024-02-10', '1', '300'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($b), 'units' => '1']]);

        Settings::set('parcel_matching', 'lowest_gain');
        $this->ledger->record($this->asset, $this->buy('2023-12-01', '1', '50'));
        $this->ledger->record($this->asset, $this->sell('2024-07-01', '0.5', '400'));

        $this->assertSame([['300.00', '1']], $this->used($sale));
    }

    public function test_removing_a_hand_picked_parcels_buy_fails_loudly_and_changes_nothing(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $b = $this->ledger->record($this->asset, $this->buy('2024-02-10', '1', '300'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($b), 'units' => '1']]);

        try {
            $this->ledger->remove($b);
            $this->fail('The removal was not refused.');
        } catch (InvalidParcelPick $e) {
            $this->assertStringContainsString('Bitcoin', $e->getMessage());
            $this->assertStringContainsString('1 Jun 2024', $e->getMessage());
        }

        $this->assertNotNull($b->fresh());
        $this->assertSame([['300.00', '1']], $this->used($sale));
    }

    public function test_shrinking_a_hand_picked_parcel_below_what_was_picked_fails_loudly(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $b = $this->ledger->record($this->asset, $this->buy('2024-02-10', '1', '300'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($b), 'units' => '1']]);

        try {
            $this->ledger->amend($b, ['units' => '0.75']);
            $this->fail('The amendment was not refused.');
        } catch (InvalidParcelPick) {
        }

        $this->assertSame('1', (string) $b->fresh()->units);
    }

    public function test_changing_the_units_of_a_hand_matched_sale_fails_loudly(): void
    {
        $buy = $this->ledger->record($this->asset, $this->buy('2024-01-10', '2', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($buy), 'units' => '1']]);

        try {
            $this->ledger->amend($sale, ['units' => '1.5']);
            $this->fail('The amendment was not refused.');
        } catch (InvalidParcelPick) {
        }

        $this->assertSame('1', (string) $sale->fresh()->units);
    }

    public function test_the_picked_units_must_add_up_to_the_units_sold(): void
    {
        $buy = $this->ledger->record($this->asset, $this->buy('2024-01-10', '2', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));

        try {
            $this->ledger->pick($sale, [['parcel' => $this->parcelOf($buy), 'units' => '0.75']]);
            $this->fail('The pick was not refused.');
        } catch (InvalidParcelPick) {
        }

        $this->assertSame(0, ParcelPick::count());
        $this->assertFalse($sale->fresh()->isHandMatched());
        $this->assertSame([['200.00', '1']], $this->used($sale));
    }

    public function test_more_cannot_be_picked_from_a_parcel_than_it_has(): void
    {
        $a = $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $this->ledger->record($this->asset, $this->buy('2024-02-10', '1', '300'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1.5', '400'));

        $this->expectException(InvalidParcelPick::class);

        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($a), 'units' => '1.5']]);
    }

    public function test_a_parcel_acquired_after_the_sale_cannot_be_picked(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $later = $this->ledger->record($this->asset, $this->buy('2024-07-10', '1', '300'));

        $this->expectException(InvalidParcelPick::class);

        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($later), 'units' => '1']]);
    }

    public function test_a_parcel_of_another_asset_cannot_be_picked(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $other = $this->ledger->record(Asset::factory()->create(), $this->buy('2024-01-10', '1', '100'));

        $this->expectException(InvalidParcelPick::class);

        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($other), 'units' => '1']]);
    }

    public function test_only_a_sale_can_be_matched_by_hand(): void
    {
        $buy = $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));

        $this->expectException(InvalidParcelPick::class);

        $this->ledger->pick($buy, [['parcel' => $this->parcelOf($buy), 'units' => '1']]);
    }

    public function test_picking_again_replaces_the_earlier_picks(): void
    {
        $a = $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $b = $this->ledger->record($this->asset, $this->buy('2024-02-10', '1', '300'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($b), 'units' => '1']]);

        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($a), 'units' => '1']]);

        $this->assertSame([['100.00', '1']], $this->used($sale));
        $this->assertSame(1, ParcelPick::count());
    }

    public function test_dropping_the_picks_returns_the_sale_to_the_configured_method(): void
    {
        $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $this->ledger->record($this->asset, $this->buy('2024-02-10', '1', '300'));
        $c = $this->ledger->record($this->asset, $this->buy('2024-03-10', '1', '200'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($c), 'units' => '1']]);

        Settings::set('parcel_matching', 'lowest_gain');
        $this->ledger->unpick($sale);

        $this->assertSame([['300.00', '1']], $this->used($sale));
        $this->assertFalse($sale->fresh()->isHandMatched());
        $this->assertSame(ParcelMatching::LowestGain, $sale->fresh()->matching);
        $this->assertSame(0, ParcelPick::count());
    }

    public function test_removing_a_hand_matched_sale_removes_its_picks(): void
    {
        $buy = $this->ledger->record($this->asset, $this->buy('2024-01-10', '1', '100'));
        $sale = $this->ledger->record($this->asset, $this->sell('2024-06-01', '1', '400'));
        $this->ledger->pick($sale, [['parcel' => $this->parcelOf($buy), 'units' => '1']]);

        $this->ledger->remove($sale);

        $this->assertSame(0, ParcelPick::count());
        $this->assertSame('1', (string) $this->parcelOf($buy)->units_remaining);
    }
}
