<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_and_asks_for_the_name_and_password(): void
    {
        $this->artisan('nugget:create-admin', ['email' => 'boss@example.com'])
            ->expectsQuestion('Name', 'The Boss')
            ->expectsQuestion('Password', 'secret-pass')
            ->expectsOutputToContain("Created admin 'boss@example.com'")
            ->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();
        $this->assertSame('The Boss', $user->name);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertSame(User::DEFAULT_TIMEZONE, $user->timezone);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }

    public function test_options_replace_the_questions(): void
    {
        $this->artisan('nugget:create-admin', [
            'email' => 'boss@example.com',
            '--name' => 'The Boss',
            '--password' => 'secret-pass',
            '--timezone' => 'Australia/Perth',
        ])->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();
        $this->assertSame('The Boss', $user->name);
        $this->assertSame('Australia/Perth', $user->timezone);
    }

    public function test_the_email_is_stored_in_lower_case(): void
    {
        $this->artisan('nugget:create-admin', [
            'email' => 'Boss@Example.COM', '--name' => 'The Boss', '--password' => 'secret-pass',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'boss@example.com']);
    }

    public function test_an_existing_member_is_promoted_and_keeps_their_details(): void
    {
        $user = User::factory()->create(['email' => 'kim@example.com', 'name' => 'Kim']);
        $password = $user->password;

        $this->artisan('nugget:create-admin', ['email' => 'Kim@Example.com'])
            ->expectsOutputToContain("Promoted 'kim@example.com' to admin")
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertSame('Kim', $user->name);
        $this->assertSame($password, $user->password);
        $this->assertSame(1, User::count());
    }

    public function test_an_existing_admin_is_left_alone(): void
    {
        User::factory()->admin()->create(['email' => 'boss@example.com']);

        $this->artisan('nugget:create-admin', ['email' => 'boss@example.com'])
            ->expectsOutputToContain('already an admin')
            ->assertSuccessful();
    }

    public function test_it_refuses_an_invalid_email(): void
    {
        $this->artisan('nugget:create-admin', [
            'email' => 'not-an-email', '--name' => 'The Boss', '--password' => 'secret-pass',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_a_short_password(): void
    {
        $this->artisan('nugget:create-admin', [
            'email' => 'boss@example.com', '--name' => 'The Boss', '--password' => 'short',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_an_unknown_timezone(): void
    {
        $this->artisan('nugget:create-admin', [
            'email' => 'boss@example.com', '--name' => 'The Boss', '--password' => 'secret-pass', '--timezone' => 'Mars/Olympus',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
