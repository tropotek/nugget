<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\PricedBy;
use App\Enums\PriceResolution;
use App\Enums\Role;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Disposal;
use App\Models\Exchange;
use App\Models\ExchangeBalance;
use App\Models\Parcel;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BalanceMismatch;
use App\Support\Decimal;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        $this->assertSame(['BTC Markets', 'BTC Markets (old account)'], $exchanges->pluck('label')->all());
        $this->assertSame(['btcmarkets', 'btcmarkets'], $exchanges->pluck('driver')->all());
        $this->assertTrue($exchanges[0]->active);
        $this->assertTrue($exchanges[0]->aud_balance->isPositive());
        $this->assertNotNull($exchanges[0]->last_synced_at);
        $this->assertFalse($exchanges[1]->active);
        $this->assertNotNull($exchanges[1]->last_error);

        $this->assertSame(1, User::where('email', 'member@example.com')->sole()->exchanges()->count());
    }

    public function test_the_synced_exchanges_get_balances_that_agree_with_the_ledger(): void
    {
        $this->runSeeder();

        $exchange = User::where('email', 'admin@example.com')->sole()->exchanges()->where('label', 'BTC Markets')->sole();
        $balances = $exchange->balances()->pluck('units', 'symbol');

        $this->assertEqualsCanonicalizing(['AUD', 'BTC', 'ADA'], $balances->keys()->all());
        $this->assertTrue($balances['AUD']->equals($exchange->aud_balance));
        $this->assertSame('0.05', (string) $balances['BTC']);

        $count = ExchangeBalance::count();
        $this->runSeeder();

        $this->assertSame($count, ExchangeBalance::count());
    }

    public function test_the_admin_is_told_of_a_balance_that_differs_from_the_ledger(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $admin = User::where('email', 'admin@example.com')->sole();
        $mismatch = $admin->notifications()->where('type', BalanceMismatch::class)->sole();

        $this->assertSame(['Cardano', '3018', '3020', '2'], [
            $mismatch->data['asset'], $mismatch->data['ledger'], $mismatch->data['on_exchange'], $mismatch->data['difference'],
        ]);
        $this->assertSame('3020', (string) ExchangeBalance::where('exchange_id', $mismatch->data['exchange_id'])->where('symbol', 'ADA')->sole()->units);
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
        $this->assertSame('BTC-AUD', $bitcoin->market_id);
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

    public function test_every_priced_asset_gets_a_price_for_each_day_since_its_first_transaction(): void
    {
        $this->travelTo('2026-10-10 03:07:00');
        $this->runSeeder();

        foreach (Asset::with('user')->get() as $asset) {
            $days = $asset->prices()->where('resolution', PriceResolution::Day)->orderBy('at')->pluck('at');

            if ($asset->pricedBy() === PricedBy::Nothing) {
                $this->assertSame(0, $asset->prices()->count(), $asset->name);

                continue;
            }

            $first = Price::day($asset->transactions()->oldest('occurred_at')->first()->occurred_at, $asset->user->timezone);

            $this->assertSame($first->toDateString(), $days->first()->toDateString(), $asset->name);
            // 2pm on 10 October in Melbourne.
            $this->assertSame('2026-10-10', $days->last()->toDateString(), $asset->name);
            $this->assertSame((int) $first->diffInDays($days->last()) + 1, $days->count(), "{$asset->name} has a gap in its days");
        }

        $this->assertTrue(Price::get()->every(fn (Price $price) => $price->bid->isPositive() && $price->ask->greaterThan($price->bid)));
    }

    public function test_the_last_48_hours_have_a_price_every_five_minutes_and_the_day_rows_close_on_them(): void
    {
        $this->travelTo('2026-10-10 03:07:00');
        $this->runSeeder();

        $bitcoin = User::where('email', 'admin@example.com')->sole()->assets()->where('name', 'Bitcoin')->sole();
        $intraday = $bitcoin->prices()->where('resolution', PriceResolution::Intraday)->orderBy('at')->get();

        $this->assertSame(48 * 12 + 1, $intraday->count());
        $this->assertSame('2026-10-08 03:05:00', $intraday->first()->at->toDateTimeString());
        $this->assertSame('2026-10-10 03:05:00', $intraday->last()->at->toDateTimeString());

        // The latest price is the newest day row, and it is the last intraday price.
        $latest = $bitcoin->prices()->latestPerAsset()->sole();
        $this->assertSame('2026-10-10', $latest->at->toDateString());
        $this->assertTrue($latest->bid->equals($intraday->last()->bid));

        // 9 October in Melbourne ended at 13:00 UTC, so it closed on the 12:55 price.
        $ninth = $bitcoin->prices()->daily('2026-10-09', '2026-10-09')->sole();
        $this->assertTrue($ninth->bid->equals($intraday->firstWhere('at', Carbon::parse('2026-10-09 12:55:00'))->bid));
    }

    public function test_seeding_again_adds_no_prices_and_gives_the_same_ones(): void
    {
        $this->travelTo('2026-10-10 03:07:00');
        $this->runSeeder();
        $count = Price::count();
        $bids = Price::orderBy('id')->limit(50)->pluck('bid')->map(fn ($bid) => (string) $bid)->all();

        $this->runSeeder();
        $this->assertSame($count, Price::count());

        Price::query()->delete();
        $this->runSeeder();
        $this->assertSame($count, Price::count());
        $this->assertSame($bids, Price::orderBy('id')->limit(50)->pluck('bid')->map(fn ($bid) => (string) $bid)->all());
    }

    public function test_it_seeds_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->runSeeder();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Exchange::count());
        $this->assertSame(0, Asset::count());
        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Price::count());
    }
}
