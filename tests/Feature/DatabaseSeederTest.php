<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\PricedBy;
use App\Enums\Role;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Exchange;
use App\Models\Parcel;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Decimal;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    private function runSeeder(): void
    {
        $this->app->make(DatabaseSeeder::class)->__invoke();
    }

    public function test_it_makes_the_dev_admin_who_can_sign_in(): void
    {
        $this->runSeeder();

        $admin = User::where('email', 'admin@example.com')->sole();
        $this->assertSame('Admin', $admin->name);
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue($admin->active);

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_dev_admin_comes_from_config(): void
    {
        config(['nugget.dev.admin' => ['name' => 'Kim Lee', 'email' => 'Kim@Example.com', 'password' => 'kims-password']]);

        $this->runSeeder();

        $admin = User::where('email', 'kim@example.com')->sole();
        $this->assertSame('Kim Lee', $admin->name);
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertFalse(User::where('email', 'admin@example.com')->exists());

        $this->post('/login', ['email' => 'kim@example.com', 'password' => 'kims-password']);
        $this->assertAuthenticatedAs($admin);
    }

    public function test_it_makes_a_member_and_an_inactive_user(): void
    {
        $this->runSeeder();

        $member = User::where('email', 'member@example.com')->sole();
        $this->assertSame(Role::Member, $member->role);
        $this->assertTrue($member->active);

        $this->assertFalse(User::where('email', 'inactive@example.com')->sole()->active);
    }

    public function test_seeding_again_adds_nothing_and_leaves_the_users_as_they_are(): void
    {
        $this->runSeeder();
        User::where('email', 'admin@example.com')->sole()->update(['name' => 'Renamed']);
        $count = User::count();

        $this->runSeeder();

        $this->assertSame($count, User::count());
        $this->assertSame('Renamed', User::where('email', 'admin@example.com')->sole()->name);
    }

    public function test_an_existing_user_with_the_admin_email_is_left_alone(): void
    {
        $mine = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Already Here']);

        $this->runSeeder();

        $this->assertSame('Already Here', $mine->fresh()->name);
        $this->assertSame(Role::Member, $mine->fresh()->role);
    }

    public function test_the_admin_and_the_member_get_exchanges(): void
    {
        $this->runSeeder();

        $admin = User::where('email', 'admin@example.com')->sole();
        $exchanges = $admin->exchanges()->orderBy('label')->get();

        $this->assertSame(['BTC Markets', 'Kraken'], $exchanges->pluck('label')->all());
        $this->assertSame(['btcmarkets', 'kraken'], $exchanges->pluck('driver')->all());
        $this->assertTrue($exchanges[0]->active);
        $this->assertTrue($exchanges[0]->aud_balance->isPositive());
        $this->assertNotNull($exchanges[0]->last_synced_at);
        $this->assertFalse($exchanges[1]->active);
        $this->assertNotNull($exchanges[1]->last_error);

        $this->assertSame(1, User::where('email', 'member@example.com')->sole()->exchanges()->count());
    }

    public function test_seeding_again_adds_no_exchanges(): void
    {
        $this->runSeeder();
        $count = Exchange::count();

        $this->runSeeder();

        $this->assertSame($count, Exchange::count());
    }

    public function test_the_admin_gets_assets_of_every_class_and_price_source(): void
    {
        $this->runSeeder();

        $assets = User::where('email', 'admin@example.com')->sole()->assets;

        $this->assertEqualsCanonicalizing(
            ['Bitcoin', 'Bitcoin cold wallet', 'Cardano', 'Litecoin', 'Gold bullion', 'Silver bullion', 'Prospected gold', 'Coin collection'],
            $assets->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [AssetClass::Crypto, AssetClass::Metal, AssetClass::Other],
            $assets->pluck('class')->unique()->values()->all(),
        );
        $this->assertEqualsCanonicalizing(
            [PricedBy::Exchange, PricedBy::Feed, PricedBy::Nothing],
            $assets->map->pricedBy()->unique()->values()->all(),
        );

        $bitcoin = $assets->firstWhere('name', 'Bitcoin');
        $this->assertSame('BTC Markets', $bitcoin->exchange->label);
        $this->assertSame('BTC/AUD', $bitcoin->market_id);
        $this->assertSame('g', $assets->firstWhere('name', 'Prospected gold')->unit);
        $this->assertFalse($assets->firstWhere('name', 'Coin collection')->in_total);
        $this->assertSame(['Litecoin'], $assets->where('archived', true)->pluck('name')->all());

        $this->assertSame(1, User::where('email', 'member@example.com')->sole()->assets()->count());
    }

    public function test_seeding_again_adds_no_assets(): void
    {
        $this->runSeeder();
        $count = Asset::count();

        $this->runSeeder();

        $this->assertSame($count, Asset::count());
    }

    public function test_the_assets_get_a_history_of_every_type_of_transaction(): void
    {
        $this->runSeeder();

        $admin = User::where('email', 'admin@example.com')->sole();
        $transactions = Transaction::whereIn('asset_id', $admin->assets()->pluck('id'))->get();

        $this->assertEqualsCanonicalizing(
            TransactionType::cases(),
            $transactions->pluck('type')->unique()->values()->all(),
        );
        $this->assertEqualsCanonicalizing(
            [TransactionSource::Manual, TransactionSource::Exchange],
            $transactions->pluck('source')->unique()->values()->all(),
        );

        foreach ($admin->assets as $asset) {
            $this->assertTrue($asset->transactions()->exists(), $asset->name);
            $held = Decimal::sum($asset->transactions->map->signedUnits());
            $this->assertFalse($held->isNegative(), "{$asset->name} is oversold");
        }

        $this->assertTrue($transactions->every(fn (Transaction $t) => $t->occurred_at->isPast()));
        $this->assertTrue(Transaction::whereIn('asset_id', User::where('email', 'member@example.com')->sole()->assets()->pluck('id'))->exists());
    }

    public function test_seeding_again_adds_no_transactions(): void
    {
        $this->runSeeder();
        $count = Transaction::count();

        $this->runSeeder();

        $this->assertSame($count, Transaction::count());
    }

    public function test_the_parcels_and_disposals_are_matched_from_the_seeded_ledger(): void
    {
        $this->runSeeder();

        foreach (Asset::with('transactions', 'parcels')->get() as $asset) {
            $this->assertTrue(
                Decimal::sum($asset->parcels->pluck('units_remaining'))->equals(Decimal::sum($asset->transactions->map->signedUnits())),
                "{$asset->name}: the parcels do not add up to the ledger",
            );
        }

        $admin = User::where('email', 'admin@example.com')->sole();
        $wallet = $admin->assets()->where('name', 'Bitcoin cold wallet')->sole();

        // The transfer carried the dates of the two buys it came from.
        $this->assertSame(
            ['2024-08-05', '2024-11-18'],
            $wallet->parcels()->orderBy('acquired_at')->get()->map(fn (Parcel $parcel) => $parcel->acquired_at->toDateString())->all(),
        );

        $this->assertTrue(Disposal::where('long_held', true)->exists());
        $this->assertTrue(Disposal::where('long_held', false)->exists());
        $this->assertTrue(Disposal::get()->contains(fn (Disposal $disposal) => $disposal->cost_base->isZero()));
    }

    public function test_seeding_again_leaves_the_same_parcels_and_disposals(): void
    {
        $this->runSeeder();
        $counts = [Parcel::count(), Disposal::count()];

        $this->runSeeder();

        $this->assertSame($counts, [Parcel::count(), Disposal::count()]);
    }

    public function test_it_seeds_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->runSeeder();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Exchange::count());
        $this->assertSame(0, Asset::count());
        $this->assertSame(0, Transaction::count());
    }
}
