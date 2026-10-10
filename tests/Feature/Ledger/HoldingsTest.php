<?php

namespace Tests\Feature\Ledger;

use App\Ledger\Holding;
use App\Ledger\Holdings;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Decimal;
use Database\Factories\TransactionFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Worked examples for the holdings query. Times are 02:00 UTC, early
 * afternoon on the same day in Melbourne, the owner's timezone.
 */
class HoldingsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Holdings $holdings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->holdings = app(Holdings::class);
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
        return $transaction->for($asset)->create(['occurred_at' => "{$day} 02:00:00"]);
    }

    private function rematch(Asset $asset): void
    {
        app(ParcelMatcher::class)->rematch($asset);
    }

    /**
     * A holding as [units, cost base, average cost].
     *
     * @return array{string, string, ?string}
     */
    private function figures(Holding $holding): array
    {
        return [
            (string) $holding->units,
            $holding->costBase->toFixed(Decimal::AUD_SCALE),
            $holding->averageCost()?->toFixed(Decimal::PRICE_SCALE),
        ];
    }

    private function seededAdmin(): User
    {
        $this->app->make(DatabaseSeeder::class)->__invoke();

        return User::where('email', 'admin@example.com')->sole();
    }

    public function test_a_holding_is_the_units_and_cost_left_in_the_open_parcels(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('3', '100'));
        $this->add($bitcoin, '2024-02-10', Transaction::factory()->buy('2', '200', '10'));
        $this->add($bitcoin, '2024-03-10', Transaction::factory()->sell('2', '300'));
        $this->rematch($bitcoin);

        $holding = $this->holdings->forUser($this->user)->sole();

        // A third of the $300 parcel is left, and the whole $410 parcel.
        $this->assertTrue($holding->asset->is($bitcoin));
        $this->assertSame(['3', '510.00', '170.00000000'], $this->figures($holding));
        $this->assertSame(2, $holding->openParcels);
        $this->assertFalse($holding->isEmpty());
    }

    public function test_a_transfer_moves_units_and_their_cost_between_holdings(): void
    {
        $exchange = $this->asset('Bitcoin', ['symbol' => 'BTC', 'unit' => 'BTC']);
        $wallet = $this->asset('Bitcoin wallet', ['symbol' => 'BTC', 'unit' => 'BTC']);
        $this->add($exchange, '2024-01-10', Transaction::factory()->buy('3', '100'));
        $this->add($exchange, '2024-02-10', Transaction::factory()->transferOut('1'));
        $this->add($wallet, '2024-02-10', Transaction::factory()->transferIn('1'));
        $this->rematch($exchange);

        $holdings = $this->holdings->forUser($this->user);

        $this->assertSame(['2', '200.00', '100.00000000'], $this->figures($holdings[$exchange->id]));
        $this->assertSame(['1', '100.00', '100.00000000'], $this->figures($holdings[$wallet->id]));
    }

    public function test_every_asset_has_a_holding_keyed_by_its_id_in_name_order(): void
    {
        $silver = $this->asset('Silver');
        $bitcoin = $this->asset('Bitcoin');
        $gold = $this->asset('Gold');

        $holdings = $this->holdings->forUser($this->user);

        $this->assertSame([$bitcoin->id, $gold->id, $silver->id], $holdings->keys()->all());
        $this->assertSame(['Bitcoin', 'Gold', 'Silver'], $holdings->map(fn (Holding $holding) => $holding->asset->name)->values()->all());
    }

    public function test_an_asset_with_no_transactions_holds_nothing(): void
    {
        $this->asset('Bitcoin');

        $holding = $this->holdings->forUser($this->user)->sole();

        $this->assertSame(['0', '0.00', null], $this->figures($holding));
        $this->assertSame(0, $holding->openParcels);
        $this->assertTrue($holding->isEmpty());
        $this->assertFalse($holding->estimated);
    }

    public function test_an_asset_sold_out_holds_nothing(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('3', '100'));
        $this->add($bitcoin, '2024-03-10', Transaction::factory()->sell('3', '300'));
        $this->rematch($bitcoin);

        $holding = $this->holdings->forUser($this->user)->sole();

        $this->assertSame(['0', '0.00', null], $this->figures($holding));
        $this->assertSame(0, $holding->openParcels);
        $this->assertTrue($holding->isEmpty());
    }

    public function test_found_units_are_held_at_no_cost(): void
    {
        $gold = $this->asset('Prospected gold');
        $this->add($gold, '2024-01-10', Transaction::factory()->found('2.5'));
        $this->rematch($gold);

        $holding = $this->holdings->forUser($this->user)->sole();

        $this->assertSame(['2.5', '0.00', '0.00000000'], $this->figures($holding));
        $this->assertFalse($holding->isEmpty());
    }

    public function test_the_average_cost_keeps_the_places_of_a_unit_price(): void
    {
        $cardano = $this->asset('Cardano');
        $this->add($cardano, '2024-01-10', Transaction::factory()->buy('3', '0.5', '0.5'));
        $this->rematch($cardano);

        $holding = $this->holdings->forUser($this->user)->sole();

        $this->assertSame(['3', '2.00', '0.66666667'], $this->figures($holding));
    }

    public function test_a_holding_is_estimated_when_an_open_parcel_is(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add($bitcoin, '2024-02-10', Transaction::factory()->buy('1', '200')->estimated());
        $this->rematch($bitcoin);

        $this->assertTrue($this->holdings->forUser($this->user)->sole()->estimated);
    }

    public function test_an_estimated_parcel_that_is_used_up_does_not_mark_the_holding(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('1', '100')->estimated());
        $this->add($bitcoin, '2024-02-10', Transaction::factory()->buy('1', '200'));
        $this->add($bitcoin, '2024-03-10', Transaction::factory()->sell('1', '300'));
        $this->rematch($bitcoin);

        $holding = $this->holdings->forUser($this->user)->sole();

        $this->assertSame(['1', '200.00', '200.00000000'], $this->figures($holding));
        $this->assertFalse($holding->estimated);
    }

    public function test_another_users_assets_are_left_out(): void
    {
        $mine = $this->asset('Bitcoin');
        $theirs = $this->asset('Bitcoin', user: User::factory()->create());
        $this->add($mine, '2024-01-10', Transaction::factory()->buy('1', '100'));
        $this->add($theirs, '2024-01-10', Transaction::factory()->buy('5', '100'));
        $this->rematch($mine);
        $this->rematch($theirs);

        $holdings = $this->holdings->forUser($this->user);

        $this->assertSame([$mine->id], $holdings->keys()->all());
        $this->assertSame('1', (string) $holdings[$mine->id]->units);
    }

    public function test_the_number_of_queries_does_not_grow_with_the_assets(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->holdings->forUser($this->user)->each(fn (Holding $holding) => [$holding->asset->name, $holding->averageCost()]);
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $buy = function (string $name): void {
            $asset = $this->asset($name);
            $this->add($asset, '2024-01-10', Transaction::factory()->buy('1', '100'));
            $this->add($asset, '2024-02-10', Transaction::factory()->buy('1', '200'));
            $this->rematch($asset);
        };

        $buy('Bitcoin');
        $forOne = $count();

        foreach (['Cardano', 'Gold', 'Silver', 'Ether'] as $name) {
            $buy($name);
        }

        $this->assertSame(2, $forOne);
        $this->assertSame($forOne, $count());
    }

    public function test_one_assets_holding_is_the_same_as_in_the_users_holdings(): void
    {
        $bitcoin = $this->asset('Bitcoin');
        $this->add($bitcoin, '2024-01-10', Transaction::factory()->buy('3', '100')->estimated());
        $this->add($bitcoin, '2024-03-10', Transaction::factory()->sell('2', '300'));
        $this->rematch($bitcoin);

        $one = $this->holdings->forAsset($bitcoin);
        $ofAll = $this->holdings->forUser($this->user)[$bitcoin->id];

        $this->assertTrue($one->asset->is($bitcoin));
        $this->assertSame(['1', '100.00', '100.00000000'], $this->figures($one));
        $this->assertSame($this->figures($ofAll), $this->figures($one));
        $this->assertSame($ofAll->openParcels, $one->openParcels);
        $this->assertTrue($one->estimated);
    }

    // Against the seeded ledger

    public function test_seeded_holdings_add_up_to_the_parcels_and_to_the_ledger(): void
    {
        $admin = $this->seededAdmin();

        $holdings = $this->holdings->forUser($admin);

        $this->assertSame($admin->assets()->count(), $holdings->count());

        foreach ($holdings as $holding) {
            $asset = $holding->asset;

            $this->assertTrue(
                $holding->units->equals(Decimal::sum($asset->parcels()->pluck('units_remaining'))),
                "{$asset->name}: the units are not the parcels' units remaining",
            );
            $this->assertTrue(
                $holding->units->equals(Decimal::sum($asset->transactions->map->signedUnits())),
                "{$asset->name}: the units are not the ledger's",
            );
            $this->assertFalse($holding->costBase->isNegative(), $asset->name);
        }
    }

    public function test_seeded_holdings_have_the_figures_worked_by_hand(): void
    {
        $holdings = $this->holdings->forUser($this->seededAdmin())
            ->keyBy(fn (Holding $holding) => $holding->asset->name)
            ->map(fn (Holding $holding) => $this->figures($holding));

        // 0.03 is left of the second buy ($7,034.29 for 0.05) and all of the third ($3,051.72).
        $this->assertSame(['0.05', '7272.29', '145445.80000000'], $holdings['Bitcoin']);
        // The transfer brought 0.01 of the first buy ($826.97) and 0.02 of the second ($2,813.72).
        $this->assertSame(['0.03', '3640.69', '121356.33333333'], $holdings['Bitcoin cold wallet']);
        $this->assertSame(['3', '13400.00', '4466.66666667'], $holdings['Gold bullion']);
        // Found at no cost: 0.17 g is left of the second find, and all of the third.
        $this->assertSame(['5.27', '0.00', '0.00000000'], $holdings['Prospected gold']);
        $this->assertSame(['1', '850.00', '850.00000000'], $holdings['Coin collection']);
    }

    public function test_seeded_holdings_of_the_member_are_their_own(): void
    {
        $this->seededAdmin();
        $member = User::where('email', 'member@example.com')->sole();

        $holding = $this->holdings->forUser($member)->sole();

        $this->assertSame($member->id, $holding->asset->user_id);
        $this->assertSame(['0.01', '1512.75', '151275.00000000'], $this->figures($holding));
    }
}
