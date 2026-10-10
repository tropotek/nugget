<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class InactiveUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_user_is_active(): void
    {
        $this->assertTrue((new User)->active);
        $this->assertTrue(User::factory()->create()->fresh()->active);
    }

    public function test_active_is_not_mass_assignable(): void
    {
        $this->assertTrue((new User(['active' => false]))->active);
    }

    public function test_an_inactive_user_cannot_log_in_and_gets_the_usual_message(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
    }

    public function test_a_signed_in_user_is_logged_out_once_deactivated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertOk();

        $user->forceFill(['active' => false])->save();

        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_inactive_user_is_sent_no_reset_link_and_gets_the_usual_reply(): void
    {
        Notification::fake();
        $user = User::factory()->inactive()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'If that email belongs to an account, a reset link is on its way.');

        Notification::assertNothingSent();
    }

    public function test_an_inactive_user_cannot_reset_their_password(): void
    {
        $user = User::factory()->inactive()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
