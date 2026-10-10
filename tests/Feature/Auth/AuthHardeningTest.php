<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const RESET_REPLY = 'If that email belongs to an account, a reset link is on its way.';

    private function failLogin(string $email, int $times = 1): void
    {
        foreach (range(1, $times) as $i) {
            $this->post('/login', ['email' => $email, 'password' => 'wrong-password']);
        }
    }

    /**
     * The message a login attempt is refused with.
     */
    private function loginError(string $email, string $password): string
    {
        $response = $this->post('/login', ['email' => $email, 'password' => $password]);
        $response->assertSessionHasErrors('email');

        return session('errors')->getBag('default')->first('email');
    }

    public function test_five_failed_logins_lock_the_email_out_for_fifteen_minutes(): void
    {
        $user = User::factory()->create();
        $this->failLogin($user->email, 5);

        $this->travel(14)->minutes();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->travel(2)->minutes();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unknown_email_is_locked_out_with_the_same_message(): void
    {
        // The message counts down the seconds left.
        $this->freezeTime();
        $user = User::factory()->create();
        $this->failLogin($user->email, 5);
        $this->failLogin('nobody@example.com', 5);
        $this->travel(14)->minutes();

        $known = $this->loginError($user->email, 'password');
        $unknown = $this->loginError('nobody@example.com', 'password');

        $this->assertNotSame(trans('auth.failed'), $unknown);
        $this->assertSame($known, $unknown);
    }

    public function test_the_lockout_counts_the_email_in_any_case(): void
    {
        $user = User::factory()->create(['email' => 'kim@example.com']);
        $this->failLogin('kim@example.com', 3);
        $this->failLogin('KIM@Example.com', 2);

        $this->post('/login', ['email' => 'kim@example.com', 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_a_lockout_does_not_reach_another_email_or_another_address(): void
    {
        $locked = User::factory()->create();
        $other = User::factory()->create();
        $this->failLogin($locked->email, 5);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->post('/login', ['email' => $locked->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($locked);

        $this->post('/logout');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->post('/login', ['email' => $other->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($other);
    }

    public function test_a_successful_login_clears_the_failed_count(): void
    {
        $user = User::factory()->create();
        $this->failLogin($user->email, 4);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/logout');

        $this->failLogin($user->email, 4);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_and_an_unknown_email_get_the_same_message(): void
    {
        $user = User::factory()->create();

        $this->assertSame(trans('auth.failed'), $this->loginError($user->email, 'wrong-password'));
        $this->assertSame(trans('auth.failed'), $this->loginError('nobody@example.com', 'wrong-password'));
    }

    public function test_the_session_id_changes_on_login(): void
    {
        $user = User::factory()->create();

        $this->get('/login');
        $before = session()->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($before, session()->getId());
    }

    public function test_recovery_answers_the_same_for_an_unknown_email(): void
    {
        Notification::fake();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::RESET_REPLY);

        Notification::assertNothingSent();
    }

    public function test_recovery_answers_the_same_when_a_link_was_only_just_sent(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::RESET_REPLY);

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_recovery_stops_sending_after_five_requests_in_fifteen_minutes(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        // Two minutes apart, so the broker's own one-a-minute limit never applies.
        foreach (range(1, 7) as $i) {
            $this->post('/forgot-password', ['email' => $user->email])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', self::RESET_REPLY);
            $this->travel(2)->minutes();
        }

        Notification::assertSentToTimes($user, ResetPassword::class, 5);

        $this->travel(15)->minutes();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentToTimes($user, ResetPassword::class, 6);
    }

    public function test_a_recovery_mail_failure_is_reported_and_the_answer_is_unchanged(): void
    {
        Exceptions::fake();
        $this->mock(Dispatcher::class)->shouldReceive('send')->andThrow(new RuntimeException('smtp down'));
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::RESET_REPLY);

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_reset_with_an_unknown_email_gets_the_bad_token_message(): void
    {
        $this->post('/reset-password', [
            'token' => 'not-the-token',
            'email' => 'nobody@example.com',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertSessionHasErrors(['email' => __('passwords.token')]);
    }
}
