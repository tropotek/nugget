<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_screen_uses_the_guest_layout(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('account-pages', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee(route('password.request'), false)
            ->assertDontSee('left-side-menu', false);
    }

    public function test_a_user_can_log_in(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_email_is_matched_without_regard_to_case(): void
    {
        $user = User::factory()->create(['email' => 'kim@example.com']);

        $this->post('/login', ['email' => ' Kim@Example.COM ', 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unknown_email_is_refused(): void
    {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_failed_login_shows_the_error_and_keeps_the_email(): void
    {
        $this->followingRedirects()
            ->from('/login')
            ->post('/login', ['email' => 'nobody@example.com', 'password' => 'password'])
            ->assertSee(__('auth.failed'))
            ->assertSee('value="nobody@example.com"', false);
    }

    public function test_remember_me_sets_the_long_lived_cookie(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => 'on'])
            ->assertCookie(Auth::guard()->getRecallerName());
    }

    public function test_a_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect(route('login', absolute: false));

        $this->assertGuest();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_a_guest_returns_to_the_page_they_asked_for_after_logging_in(): void
    {
        Route::get('/somewhere', fn () => 'ok')->middleware(['web', 'auth']);
        $user = User::factory()->create();

        $this->get('/somewhere')->assertRedirect(route('login'));

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/somewhere');
    }

    public function test_a_signed_in_user_is_sent_away_from_the_login_screen(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/login')
            ->assertRedirect(route('dashboard'));
    }

    public function test_the_top_bar_shows_the_user_and_a_log_out_button(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Kim Lee']))
            ->get('/')
            ->assertOk()
            ->assertSee('Kim Lee')
            ->assertSee('action="'.route('logout').'"', false);
    }

    public function test_there_is_no_registration(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => 'new@example.com'])->assertNotFound();
    }
}
