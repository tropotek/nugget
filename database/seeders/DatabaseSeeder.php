<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\User;
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
        $this->exchange($admin, 'Kraken', fn (ExchangeFactory $exchange) => $exchange
            ->inactive()
            ->failed('kraken {"error":["EAPI:Invalid key"]}')
            ->state(['driver' => 'kraken']));
        $membersExchange = $this->exchange($member, 'BTC Markets', fn (ExchangeFactory $exchange) => $exchange->synced('75.00'));

        $this->asset($admin, 'Bitcoin', fn (AssetFactory $asset) => $asset->crypto()->onExchange($btcMarkets, 'BTC/AUD'));
        $this->asset($admin, 'Cardano', fn (AssetFactory $asset) => $asset
            ->onExchange($btcMarkets, 'ADA/AUD')
            ->state(['symbol' => 'ADA', 'unit' => 'ADA']));
        $this->asset($admin, 'Gold bullion', fn (AssetFactory $asset) => $asset->metal());
        $this->asset($admin, 'Silver bullion', fn (AssetFactory $asset) => $asset
            ->metal()
            ->state(['symbol' => 'XAG', 'feed_symbol' => 'XAG/AUD']));
        $this->asset($admin, 'Prospected gold', fn (AssetFactory $asset) => $asset
            ->metal()
            ->state(['unit' => 'g', 'notes' => 'Found with the detector. Each find is entered at no cost.']));
        $this->asset($admin, 'Coin collection', fn (AssetFactory $asset) => $asset->other()->state(['in_total' => false]));

        $this->asset($member, 'Bitcoin', fn (AssetFactory $asset) => $asset->crypto()->onExchange($membersExchange, 'BTC/AUD'));
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
