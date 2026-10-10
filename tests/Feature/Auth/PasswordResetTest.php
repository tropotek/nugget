<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_forgot_password_screen_uses_the_guest_layout(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('account-pages', false)
            ->assertSee('name="email"', false)
            ->assertDontSee('left-side-menu', false);
    }

    public function test_a_reset_link_is_emailed(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'kim@example.com']);

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'Kim@Example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', __('passwords.sent'));

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_reset_link_opens_the_reset_screen(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email))
                ->assertOk()
                ->assertSee('account-pages', false)
                ->assertSee('value="'.$notification->token.'"', false)
                ->assertSee('value="'.$user->email.'"', false)
                ->assertSee('name="password_confirmation"', false);

            return true;
        });
    }

    public function test_the_password_can_be_reset_with_the_emailed_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'))
                ->assertSessionHas('status', __('passwords.reset'));

            return true;
        });

        $this->assertTrue(Hash::check('a-new-password', $user->fresh()->password));

        $this->post('/login', ['email' => $user->email, 'password' => 'a-new-password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_token_does_not_reset_the_password(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'not-the-token',
            'email' => $user->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'a-new-password',
                'password_confirmation' => 'something-else',
            ])->assertSessionHasErrors('password');

            return true;
        });

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_token_works_only_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $reset = fn (string $password) => $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => $password,
                'password_confirmation' => $password,
            ]);

            $reset('a-new-password')->assertSessionHasNoErrors();
            $reset('another-password')->assertSessionHasErrors('email');

            return true;
        });

        $this->assertTrue(Hash::check('a-new-password', $user->fresh()->password));
    }
}
