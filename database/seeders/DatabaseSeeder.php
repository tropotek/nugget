<?php

namespace Database\Seeders;

use App\Enums\PriceResolution;
use App\Enums\Role;
use App\Enums\TransactionSource;
use App\Ledger\Holdings;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Price;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\BalanceMismatch;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\AssetFactory;
use Database\Factories\ExchangeFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Development data: users to sign in as, and made-up data to look at.
 *
 * It only adds what is missing, so it is safe to run on a database that is in
 * use, and it does nothing outside the local and testing environments.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('Seed data is for development. Nothing was seeded in '.app()->environment().'.');

            return;
        }

        $admin = config('nugget.dev.admin');

        $admin = $this->user($admin['email'], $admin['name'], $admin['password'], Role::Admin);
        $member = $this->user('member@example.com', 'Member', 'password');
        $this->user('inactive@example.com', 'Inactive Member', 'password', active: false);

        $btcMarkets = $this->exchange($admin, 'BTC Markets', fn (ExchangeFactory $exchange) => $exchange->synced('1520.45'));
        $this->exchange($admin, 'BTC Markets (old account)', fn (ExchangeFactory $exchange) => $exchange
            ->inactive()
            ->failed('HTTP request returned status code 401: {"code":"InvalidAPIKey","message":"invalid API key"}'));
        $membersExchange = $this->exchange($member, 'BTC Markets', fn (ExchangeFactory $exchange) => $exchange->synced('75.00'));

        $bitcoin = $this->asset($admin, 'Bitcoin', fn (AssetFactory $asset) => $asset->crypto()->onExchange($btcMarkets, 'BTC-AUD'));
        $coldWallet = $this->asset($admin, 'Bitcoin cold wallet', fn (AssetFactory $asset) => $asset
            ->crypto()
            ->onExchange($btcMarkets, 'BTC-AUD')
            ->state(['notes' => 'Held off the exchange, and priced by its market.']));
        $cardano = $this->asset($admin, 'Cardano', fn (AssetFactory $asset) => $asset
            ->onExchange($btcMarkets, 'ADA-AUD')
            ->state(['symbol' => 'ADA', 'unit' => 'ADA']));
        $litecoin = $this->asset($admin, 'Litecoin', fn (AssetFactory $asset) => $asset
            ->onExchange($btcMarkets, 'LTC-AUD')
            ->archived()
            ->state(['symbol' => 'LTC', 'unit' => 'LTC', 'notes' => 'Sold out and archived.']));
        $gold = $this->asset($admin, 'Gold bullion', fn (AssetFactory $asset) => $asset->metal());
        $silver = $this->asset($admin, 'Silver bullion', fn (AssetFactory $asset) => $asset
            ->metal()
            ->state(['symbol' => 'XAG', 'feed_symbol' => 'XAG/AUD']));
        $prospected = $this->asset($admin, 'Prospected gold', fn (AssetFactory $asset) => $asset
            ->metal()
            ->state(['unit' => 'g', 'notes' => 'Found with the detector. Each find is entered at no cost.']));
        $coins = $this->asset($admin, 'Coin collection', fn (AssetFactory $asset) => $asset->other()->state(['in_total' => false]));

        $membersBitcoin = $this->asset($member, 'Bitcoin', fn (AssetFactory $asset) => $asset->crypto()->onExchange($membersExchange, 'BTC-AUD'));

        // Times are UTC. The history spans three financial years, with sales
        // on both sides of the 12-month line, to exercise the tax report.
        $this->history($bitcoin, TransactionSource::Exchange, [
            ['2024-08-05 01:14', 'buy', '0.08', '82000', '55.76'],
            ['2024-11-18 22:40', 'buy', '0.05', '139500', '59.29'],
            ['2025-03-03 04:02', 'sell', '0.04', '148200', '50.39'],
            ['2025-09-15 23:30', 'sell', '0.03', '176400', '44.98'],
            ['2026-02-09 00:45', 'buy', '0.02', '151300', '25.72'],
            ['2026-03-10 05:00', 'transferOut', '0.03'],
        ]);
        $this->history($coldWallet, TransactionSource::Manual, [
            ['2026-03-10 05:00', 'transferIn', '0.03'],
        ]);
        $this->history($cardano, TransactionSource::Exchange, [
            ['2024-09-10 05:20', 'buy', '2500', '0.52', '11.05'],
            ['2025-01-22 03:15', 'buy', '1500', '1.58', '20.15'],
            ['2025-06-30 13:00', 'reward', '18.5', '0.86'],
            ['2025-12-01 02:00', 'sell', '1000', '0.71', '6.04'],
            ['2026-07-04 01:00', 'adjust', '-0.5'],
        ]);
        $this->history($litecoin, TransactionSource::Exchange, [
            ['2024-07-10 03:30', 'buy', '5', '105', '4.46'],
            ['2025-02-14 00:20', 'sell', '5', '198', '8.42'],
        ]);
        $this->history($gold, TransactionSource::Manual, [
            ['2024-07-19 02:30', 'buy', '2', '3640'],
            ['2025-10-08 01:00', 'buy', '1', '6120'],
        ]);
        $this->history($silver, TransactionSource::Manual, [
            ['2024-12-05 03:00', 'buy', '50', '49.20', '15'],
            ['2026-04-17 02:00', 'sell', '20', '52.80', '10'],
        ]);
        $this->history($prospected, TransactionSource::Manual, [
            ['2024-09-21 06:00', 'found', '2.35'],
            ['2025-04-12 04:30', 'found', '0.82'],
            ['2025-08-30 05:15', 'found', '5.1'],
            ['2026-01-15 01:00', 'sell', '3', '148.50'],
        ]);
        $this->history($coins, TransactionSource::Manual, [
            ['2024-07-02 03:00', 'buy', '1', '850'],
        ]);
        $this->history($membersBitcoin, TransactionSource::Exchange, [
            ['2026-01-05 02:00', 'buy', '0.01', '150000', '12.75'],
        ]);

        // Parcels and disposals are never seeded by hand: they are worked out from the ledger above.
        foreach ([$admin, $member] as $user) {
            $user->assets()->get()->each(fn (Asset $asset) => app(ParcelMatcher::class)->rematch($asset));
        }

        // What each synced exchange last said the account held: the ledger's own figures, and the AUD.
        $this->balances($btcMarkets, [$bitcoin, $cardano, $litecoin]);
        $this->balances($membersExchange, [$membersBitcoin]);

        // The exchange has 2 ADA the ledger does not: a staking reward nobody has entered yet.
        $this->mismatch($btcMarkets, $cardano, '2');

        // What one unit sold for at the asset's first transaction, and sells for now.
        $this->prices($bitcoin, '80000', '158000');
        $this->prices($coldWallet, '152000', '158000');
        $this->prices($cardano, '0.5', '0.72');
        $this->prices($litecoin, '104', '171');
        $this->prices($gold, '3620', '6310');
        $this->prices($silver, '48.9', '54.6');
        $this->prices($prospected, '127', '203');
        $this->prices($membersBitcoin, '149000', '158000');
    }

    /**
     * Give an asset a price history, unless it has one: a day row for each
     * of its owner's days since its first transaction, and an intraday row
     * every 5 minutes for the last 48 hours.
     *
     * The price drifts from the first figure to the second with a wobble of
     * up to 2% a day and 0.3% within the two days. The wobble is worked out
     * from the asset and the time, so it is the same on every run. A day
     * that has intraday rows closes at the last of them.
     */
    private function prices(Asset $asset, string $from, string $to): void
    {
        $first = $asset->transactions()->min('occurred_at');

        if ($asset->prices()->exists() || ! $first) {
            return;
        }

        $timezone = $asset->user->timezone;
        $now = CarbonImmutable::now('UTC');
        $rows = [];

        $row = function (CarbonImmutable $at, PriceResolution $resolution, Decimal $bid) use (&$rows, $asset, $now) {
            $rows["{$resolution->value} {$at->toDateTimeString()}"] = [
                'asset_id' => $asset->id,
                'at' => $at->toDateTimeString(),
                'bid' => $bid->toFixed(Decimal::PRICE_SCALE),
                // A spread of 0.2%.
                'ask' => $bid->mul('1.002')->toFixed(Decimal::PRICE_SCALE),
                'resolution' => $resolution->value,
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ];
        };

        // The price moved by a whole number of hundredths of a percent, between -$range and $range.
        $wobble = fn (Decimal $price, string $seed, int $range) => $price
            ->mul(10000 + crc32("{$asset->name} {$seed}") % (2 * $range + 1) - $range)
            ->div(10000, 6);

        $start = Price::day(CarbonImmutable::parse($first, 'UTC'), $timezone);
        $today = Price::day($now, $timezone);
        $days = max(1, (int) $start->diffInDays($today));

        for ($day = 0; $day <= $days; $day++) {
            $drifted = Decimal::of($from)->add(Decimal::of($to)->sub($from)->mul($day)->div($days, 6));
            $date = $start->addDays($day);

            $row($date, PriceResolution::Day, $wobble($drifted, $date->toDateString(), 200));
        }

        $latest = $now->startOfMinute()->subMinutes($now->minute % 5);

        for ($at = $latest->subHours(48); $at->lessThanOrEqualTo($latest); $at = $at->addMinutes(5)) {
            $bid = $wobble(Decimal::of($to), $at->toDateTimeString(), 30);

            $row($at, PriceResolution::Intraday, $bid);
            $row(Price::day($at, $timezone), PriceResolution::Day, $bid);
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            Price::insert($chunk);
        }
    }

    /**
     * Give an asset its transactions, unless it already has some.
     *
     * Each row is a time, a TransactionFactory state and that state's
     * arguments. A row from an exchange gets a made-up trade id.
     *
     * @param  list<array<int, string>>  $rows
     */
    private function history(Asset $asset, TransactionSource $source, array $rows): void
    {
        if ($asset->transactions()->exists()) {
            return;
        }

        foreach ($rows as $number => [$occurredAt, $state]) {
            $transaction = Transaction::factory()->for($asset)->{$state}(...array_slice($rows[$number], 2));

            if ($source === TransactionSource::Exchange) {
                $transaction = $transaction->fromExchange("seed-{$asset->id}-".($number + 1));
            }

            $transaction->create(['occurred_at' => $occurredAt]);
        }
    }

    /**
     * The user's asset with this name, created if there is none.
     *
     * @param  Closure(AssetFactory): AssetFactory  $states
     */
    private function asset(User $user, string $name, Closure $states): Asset
    {
        return $user->assets()->where('name', $name)->first()
            ?? $states(Asset::factory())->for($user)->create(['name' => $name]);
    }

    /**
     * Give an exchange the balances of a sync, unless it has some: its AUD
     * balance, and what is held of each of these assets.
     *
     * @param  list<Asset>  $assets
     */
    private function balances(Exchange $exchange, array $assets): void
    {
        if ($exchange->balances()->exists()) {
            return;
        }

        $held = ['AUD' => $exchange->aud_balance];

        foreach ($assets as $asset) {
            $held[$asset->symbol] = app(Holdings::class)->forAsset($asset)->units;
        }

        foreach ($held as $symbol => $units) {
            if (! $units->isZero()) {
                $exchange->balances()->create(['symbol' => $symbol, 'units' => $units, 'available' => $units]);
            }
        }
    }

    /**
     * Put an exchange's balance of an asset this many units above the ledger
     * and tell its owner, unless they have a balance mismatch already.
     */
    private function mismatch(Exchange $exchange, Asset $asset, string $extra): void
    {
        $owner = $exchange->user;
        $balance = $exchange->balances()->where('symbol', $asset->symbol)->first();

        if (! $balance || $owner->notifications()->where('type', BalanceMismatch::class)->exists()) {
            return;
        }

        $holding = app(Holdings::class)->forAsset($asset);
        $onExchange = $holding->units->add($extra);

        $balance->update(['units' => $onExchange, 'available' => $onExchange]);
        $owner->notify(new BalanceMismatch($exchange, $holding, $onExchange));
    }

    /**
     * The user's exchange with this label, created if there is none. Its key
     * and secret are random, so a sync against the real exchange fails.
     *
     * @param  Closure(ExchangeFactory): ExchangeFactory  $states
     */
    private function exchange(User $user, string $label, Closure $states): Exchange
    {
        return $user->exchanges()->where('label', $label)->first()
            ?? $states(Exchange::factory())->for($user)->create(['label' => $label]);
    }

    /**
     * The user with this email, created if there is none. An existing user is not changed.
     */
    private function user(string $email, string $name, string $password, Role $role = Role::Member, bool $active = true): User
    {
        $email = Str::lower($email);

        if ($user = User::where('email', $email)->first()) {
            return $user;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => $role, 'active' => $active])->save();

        return $user;
    }
}
