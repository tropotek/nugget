<?php

namespace Tests\Feature\Ledger;

use App\Ledger\ParcelMatcher;
use App\Ledger\PortfolioValue;
use App\Ledger\Valuation;
use App\Ledger\Valuations;
use App\Models\Asset;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Decimal;
use Database\Factories\TransactionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Worked examples for valuing holdings at the latest price. Times are 02:00
 * UTC, early afternoon on the same day in Melbourne, the owner's timezone.
 */
class ValuationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Valuations $valuations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->valuations = app(Valuations::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function asset(string $name, array $attributes = [], ?User $user = null): Asset
    {
        return Asset::factory()->for($user ?? $this->user)->create(['name' => $name, ...$attributes]);
    }

    private function add(Asset $asset, string $day, TransactionFactory $transaction): Transaction
    {
        $added = $transaction->for($asset)->create(['occurred_at' => "{$day} 02:00:00"]);

        app(ParcelMatcher::class)->rematch($asset);

        return $added;
    }

    private function price(Asset $asset, string $day, string $bid, ?string $ask = null): Price
    {
        return Price::factory()->for($asset)->day($day)->quoted($bid, $ask ?? $bid)->create();
    }

    /**
     * A valuation as [value, gain, gain percent].
     *
     * @return array{?string, ?string, ?string}
     */
    private function figures(Valuation|PortfolioValue $valuation): array
    {
        return [
            $valuation->value()?->toFixed(Decimal::AUD_SCALE),
            $valuation->gain()?->toFixed(Decimal::AUD_SCALE),
            $valuation->gainPercent()?->toFixed(2),
        ];
    }

    public function test_a_holding_is_valued_at_what_its_units_would_sell_for(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('3', '100'));
        $this->add($bitcoin, '2024-02-10', Transaction::factory()->buy('2', '200', '10'));
        $this->add($bitcoin, '2024-03-10', Transaction::factory()->sell('2', '300'));
        $this->price($bitcoin, '2024-03-11', '250', '260');

        $valuation = $this->valuations->forUser($this->user)->sole();

        // 3 held at a cost of $510, selling at the $250 bid.
        $this->assertTrue($valuation->holding->asset->is($bitcoin));
        $this->assertSame('510.00', $valuation->holding->costBase->toFixed(2));
        $this->assertSame(['750.00', '240.00', '47.06'], $this->figures($valuation));
        $this->assertTrue($valuation->isPriced());
    }

    public function test_the_price_is_the_newest_day_row(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('2', '100'));
        $this->price($bitcoin, '2024-03-10', '150');
        $newest = $this->price($bitcoin, '2024-03-12', '90');
        $this->price($bitcoin, '2024-03-11', '170');
        Price::factory()->for($bitcoin)->at('2024-03-12 05:00:00')->quoted('500', '500')->create();

        $valuation = $this->valuations->forUser($this->user)->sole();

        $this->assertTrue($valuation->price->is($newest));
        $this->assertSame(['180.00', '-20.00', '-10.00'], $this->figures($valuation));
    }

    public function test_an_asset_with_no_price_has_no_value(): void
    {
        $coins = $this->asset('Coin collection');
        $this->add($coins, '2024-01-10', Transaction::factory()->buy('1', '850'));

        $valuation = $this->valuations->forUser($this->user)->sole();

        $this->assertNull($valuation->price);
        $this->assertFalse($valuation->isPriced());
        $this->assertSame([null, null, null], $this->figures($valuation));
    }

    public function test_units_held_at_no_cost_have_a_gain_but_no_percentage(): void
    {
        $gold = $this->asset('Prospected gold');
        $this->add($gold, '2024-01-10', Transaction::factory()->found('2.5'));
        $this->price($gold, '2024-03-11', '200');

        $this->assertSame(['500.00', '500.00', null], $this->figures($this->valuations->forUser($this->user)->sole()));
    }

    public function test_an_asset_with_nothing_held_is_worth_nothing(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $this->price($bitcoin, '2024-03-11', '200');

        $this->assertSame(['0.00', '0.00', null], $this->figures($this->valuations->forUser($this->user)->sole()));
    }

    public function test_the_value_is_rounded_to_the_cent(): void
    {
        $cardano = $this->asset('Cardano');
        $this->add($cardano, '2024-01-10', Transaction::factory()->buy('3.33333333', '0.3'));
        $this->price($cardano, '2024-03-11', '0.71234567');

        // 3.33333333 × 0.71234567 = 2.37448554…, against a cost of $1.00.
        $this->assertSame(['2.37', '1.37', '137.00'], $this->figures($this->valuations->forUser($this->user)->sole()));
    }

    public function test_every_asset_has_a_valuation_keyed_by_its_id_in_name_order(): void
    {
        $silver = $this->asset('Silver');
        $bitcoin = $this->asset('Bitcoin');
        $this->asset('Bitcoin', user: User::factory()->create());

        $this->assertSame([$bitcoin->id, $silver->id], $this->valuations->forUser($this->user)->keys()->all());
    }

    public function test_one_assets_valuation_is_the_same_as_in_the_users_valuations(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('3', '100'));
        $this->price($bitcoin, '2024-03-10', '150');
        $this->price($bitcoin, '2024-03-11', '120');

        $one = $this->valuations->forAsset($bitcoin);

        $this->assertSame(['360.00', '60.00', '20.00'], $this->figures($one));
        $this->assertSame($this->figures($this->valuations->forUser($this->user)[$bitcoin->id]), $this->figures($one));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_assets(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->valuations->forUser($this->user)->each(fn (Valuation $valuation) => $valuation->gainPercent());
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $buy = function (string $name): void {
            $asset = $this->asset($name);
            $this->add($asset, '2024-01-10', Transaction::factory()->buy('1', '100'));
            $this->price($asset, '2024-03-10', '150');
        };

        $buy('Bitcoin');
        $forOne = $count();

        foreach (['Cardano', 'Gold', 'Silver'] as $name) {
            $buy($name);
        }

        $this->assertSame(3, $forOne);
        $this->assertSame($forOne, $count());
    }

    // Portfolio totals

    public function test_the_totals_add_up_the_priced_holdings(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $gold = $this->asset('Gold');
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('2', '100'));
        $this->add($gold, '2024-01-10', Transaction::factory()->buy('1', '1000'));
        $this->price($bitcoin, '2024-03-11', '150');
        $this->price($gold, '2024-03-11', '900');

        $totals = PortfolioValue::of($this->valuations->forUser($this->user));

        $this->assertSame('1200.00', $totals->costBase->toFixed(2));
        // $300 and $900 against $200 and $1,000.
        $this->assertSame(['1200.00', '0.00', '0.00'], $this->figures($totals));
        $this->assertSame(0, $totals->unpriced);
    }

    public function test_the_totals_leave_out_an_asset_that_does_not_count_toward_the_total(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $house = $this->asset('Coin collection', ['in_total' => false]);
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('2', '100'));
        $this->add($house, '2024-01-10', Transaction::factory()->buy('1', '5000'));
        $this->price($bitcoin, '2024-03-11', '150');
        $this->price($house, '2024-03-11', '9000');

        $totals = PortfolioValue::of($this->valuations->forUser($this->user));

        $this->assertSame('200.00', $totals->costBase->toFixed(2));
        $this->assertSame(['300.00', '100.00', '50.00'], $this->figures($totals));
    }

    public function test_the_totals_leave_out_a_holding_with_no_price_and_count_it(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $coins = $this->asset('Coin collection');
        $this->asset('Empty and unpriced');
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('2', '100'));
        $this->add($coins, '2024-01-10', Transaction::factory()->buy('1', '850'));
        $this->price($bitcoin, '2024-03-11', '150');

        $totals = PortfolioValue::of($this->valuations->forUser($this->user));

        // The cost of the coins is left out with their value, so the gain is not understated.
        $this->assertSame('200.00', $totals->costBase->toFixed(2));
        $this->assertSame(['300.00', '100.00', '50.00'], $this->figures($totals));
        $this->assertSame(1, $totals->unpriced);
    }

    public function test_the_totals_of_nothing_are_zero(): void
    {
        $totals = PortfolioValue::of($this->valuations->forUser($this->user));

        $this->assertSame(['0.00', '0.00', null], $this->figures($totals));
        $this->assertSame(0, $totals->unpriced);
    }
}
