<?php

namespace Tests\Feature\Ledger;

use App\Enums\ParcelMatching;
use App\Facades\Settings;
use App\Ledger\Matching\Fifo;
use App\Ledger\Matching\LowestGain;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Parcel;
use App\Models\Transaction;
use App\Support\Decimal;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two matching methods on the same ledgers. Times are 02:00 UTC, early
 * afternoon on the same day in Melbourne, the owner's timezone.
 */
class LowestGainMatchingTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asset = Asset::factory()->create();
    }

    private function add(string $day, TransactionFactory $transaction, ?Asset $asset = null): Transaction
    {
        return $transaction->for($asset ?? $this->asset)->create(['occurred_at' => "{$day} 02:00:00"]);
    }

    /**
     * Rematch by a method and give the sale's disposals as [parcel cost base, units, gain], in the order they were written.
     *
     * @return list<array{string, string, string}>
     */
    private function matched(Transaction $sale, ?ParcelMatching $method): array
    {
        app(ParcelMatcher::class)->rematch($this->asset, $method);

        return $sale->disposals()->orderBy('id')->get()
            ->map(fn (Disposal $d) => [$d->parcel->cost_base->toFixed(2), (string) $d->units, $d->gain->toFixed(2)])
            ->all();
    }

    /**
     * The gain tax is paid on: a gain on a long-held parcel counts for half, a loss counts in full.
     */
    private function taxableGain(Transaction $sale): string
    {
        return Decimal::sum($sale->disposals()->get()->map(
            fn (Disposal $d) => $d->long_held && $d->gain->isPositive() ? $d->gain->div(2, 2) : $d->gain,
        ))->toFixed(2);
    }

    public function test_each_method_has_its_strategy(): void
    {
        $this->assertInstanceOf(Fifo::class, ParcelMatching::Fifo->strategy());
        $this->assertInstanceOf(LowestGain::class, ParcelMatching::LowestGain->strategy());
    }

    public function test_fifo_uses_the_oldest_parcel_and_lowest_gain_the_dearest(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-02-10', Transaction::factory()->buy('1', '300'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '400'));

        $this->assertSame([['100.00', '1', '300.00']], $this->matched($sale, ParcelMatching::Fifo));
        $this->assertSame([['300.00', '1', '100.00']], $this->matched($sale, ParcelMatching::LowestGain));
    }

    public function test_lowest_gain_works_through_the_parcels_from_the_smallest_gain_up(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-02-10', Transaction::factory()->buy('1', '300'));
        $this->add('2024-03-10', Transaction::factory()->buy('1', '200'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('2.5', '400'));

        $this->assertSame(
            [['100.00', '1', '300.00'], ['300.00', '1', '100.00'], ['200.00', '0.5', '100.00']],
            $this->matched($sale, ParcelMatching::Fifo),
        );
        $this->assertSame(
            [['300.00', '1', '100.00'], ['200.00', '1', '200.00'], ['100.00', '0.5', '150.00']],
            $this->matched($sale, ParcelMatching::LowestGain),
        );

        $this->assertSame(['0.5', '0', '0'], $this->asset->parcels()->orderBy('acquired_at')->get()
            ->map(fn (Parcel $p) => (string) $p->units_remaining)->all());
    }

    public function test_a_long_held_parcel_wins_when_half_its_gain_is_the_smaller(): void
    {
        // Held over 12 months: a gain of 300 is taxed as 150, less than the newer parcel's 160.
        $this->add('2023-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-03-10', Transaction::factory()->buy('1', '240'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '400'));

        $this->assertSame([['100.00', '1', '300.00']], $this->matched($sale, ParcelMatching::LowestGain));
        $this->assertTrue($sale->disposals()->sole()->long_held);
        $this->assertSame('150.00', $this->taxableGain($sale));
    }

    public function test_a_newer_parcel_wins_when_its_whole_gain_is_smaller_than_half_the_long_held_one(): void
    {
        // A gain of 140 beats the long-held parcel's 300 taxed as 150.
        $this->add('2023-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-03-10', Transaction::factory()->buy('1', '260'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '400'));

        $this->assertSame([['260.00', '1', '140.00']], $this->matched($sale, ParcelMatching::LowestGain));
        $this->assertSame('140.00', $this->taxableGain($sale));
    }

    public function test_a_loss_on_a_long_held_parcel_counts_in_full(): void
    {
        // The long-held loss of 100 is not halved to 50, so it beats the newer loss of 70.
        $this->add('2023-01-10', Transaction::factory()->buy('1', '500'));
        $this->add('2024-03-10', Transaction::factory()->buy('1', '470'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '400'));

        $this->assertSame([['500.00', '1', '-100.00']], $this->matched($sale, ParcelMatching::LowestGain));
    }

    public function test_the_biggest_loss_is_used_first(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '450'));
        $this->add('2024-02-10', Transaction::factory()->buy('1', '600'));
        $this->add('2024-03-10', Transaction::factory()->buy('1', '300'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('2', '400'));

        $this->assertSame(
            [['600.00', '1', '-200.00'], ['450.00', '1', '-50.00']],
            $this->matched($sale, ParcelMatching::LowestGain),
        );
    }

    public function test_parcels_with_the_same_gain_go_oldest_first(): void
    {
        $this->add('2024-02-10', Transaction::factory()->buy('1', '200'));
        $this->add('2024-01-10', Transaction::factory()->buy('1', '200'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '400'));

        $this->matched($sale, ParcelMatching::LowestGain);

        $this->assertSame('2024-01-10', $sale->disposals()->sole()->parcel->acquired_at->toDateString());
    }

    public function test_a_part_used_parcel_is_judged_by_its_cost_per_unit(): void
    {
        // The first sale leaves half a unit of the dearer parcel. What is left cost 100 in all,
        // less than the other parcel's 150, but at 200 a unit it is still the dearer one.
        $this->add('2024-01-10', Transaction::factory()->buy('1', '150'));
        $this->add('2024-02-10', Transaction::factory()->buy('4', '200'));
        $this->add('2024-03-01', Transaction::factory()->sell('3.5', '400'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('0.5', '400'));

        $this->assertSame([['800.00', '0.5', '100.00']], $this->matched($sale, ParcelMatching::LowestGain));
    }

    public function test_the_fee_on_a_sale_does_not_change_the_order(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-02-10', Transaction::factory()->buy('1', '300'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '400', '25'));

        $this->assertSame([['300.00', '1', '75.00']], $this->matched($sale, ParcelMatching::LowestGain));
    }

    public function test_lowest_gain_never_gives_a_bigger_taxable_gain_than_fifo(): void
    {
        $this->add('2023-02-01', Transaction::factory()->buy('2', '120', '4'));
        $this->add('2023-11-15', Transaction::factory()->buy('1.5', '310', '6'));
        $this->add('2024-01-20', Transaction::factory()->buy('3', '95'));
        $this->add('2024-04-02', Transaction::factory()->buy('1', '420', '5'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('4.25', '280', '11.90'));

        $this->matched($sale, ParcelMatching::Fifo);
        $fifo = $this->taxableGain($sale);

        $this->matched($sale, ParcelMatching::LowestGain);
        $lowest = $this->taxableGain($sale);

        $this->assertTrue(Decimal::of($lowest)->lessThan($fifo), "lowest gain {$lowest} is not below FIFO {$fifo}");
        $this->assertTrue(Decimal::sum($sale->disposals()->pluck('units'))->equals('4.25'));
        $this->assertTrue(Decimal::sum($sale->disposals()->get()->pluck('proceeds'))->equals($sale->total));
    }

    public function test_a_transfer_out_and_an_adjustment_down_still_take_the_oldest(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-02-10', Transaction::factory()->buy('1', '300'));
        $this->add('2024-03-01', Transaction::factory()->transferOut('0.5'));
        $this->add('2024-03-02', Transaction::factory()->adjust('-0.25'));

        app(ParcelMatcher::class)->rematch($this->asset, ParcelMatching::LowestGain);

        $this->assertSame(['0.25', '1'], $this->asset->parcels()->orderBy('acquired_at')->get()
            ->map(fn (Parcel $p) => (string) $p->units_remaining)->all());
    }

    public function test_the_setting_chooses_the_method_when_none_is_named(): void
    {
        $this->add('2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add('2024-02-10', Transaction::factory()->buy('1', '300'));
        $sale = $this->add('2024-06-01', Transaction::factory()->sell('1', '400'));

        $this->assertSame([['100.00', '1', '300.00']], $this->matched($sale, null));

        Settings::set('parcel_matching', 'lowest_gain');

        $this->assertSame([['300.00', '1', '100.00']], $this->matched($sale, null));
    }
}
