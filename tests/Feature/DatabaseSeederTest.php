<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Exchange;
use App\Models\User;
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

    public function test_it_seeds_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->runSeeder();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Exchange::count());
    }
}
