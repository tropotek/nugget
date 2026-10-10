<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\SetPassword;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class UserManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin', 'email' => 'ada@example.com']);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'name' => 'Kim Lee',
            'email' => 'kim@example.com',
            'timezone' => 'Australia/Perth',
            'role' => 'member',
            'active' => '1',
        ];
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/users')->assertRedirect(route('login'));
    }

    public function test_a_member_cannot_reach_any_user_screen(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member);

        $this->get('/users')->assertForbidden();
        $this->get('/users/create')->assertForbidden();
        $this->post('/users', $this->form())->assertForbidden();
        $this->get("/users/{$this->admin->id}/edit")->assertForbidden();
        $this->get("/users/{$member->id}/edit")->assertForbidden();
        $this->put("/users/{$member->id}", $this->form(['role' => 'admin']))->assertForbidden();

        $this->assertSame(Role::Member, $member->fresh()->role);
        $this->assertSame(2, User::count());
    }

    public function test_the_sidebar_shows_users_to_an_admin_only(): void
    {
        $this->actingAs($this->admin)->get('/')->assertSee('href="'.route('users.index').'"', false);

        $this->actingAs(User::factory()->create())->get('/')->assertDontSee('href="'.route('users.index').'"', false);
    }

    public function test_the_list_shows_every_user(): void
    {
        User::factory()->create(['name' => 'Kim Lee', 'email' => 'kim@example.com']);
        User::factory()->inactive()->create(['name' => 'Old Mate']);

        $this->actingAs($this->admin)
            ->get('/users')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Users</h4>', false)
            ->assertSeeInOrder(['Ada Admin', 'Admin', 'Kim Lee', 'kim@example.com', 'Member', 'Old Mate', 'Inactive'])
            ->assertSee('href="'.route('users.create').'"', false);
    }

    public function test_the_list_can_be_searched_by_name_or_email(): void
    {
        User::factory()->create(['name' => 'Kim Lee', 'email' => 'kim@example.com']);
        User::factory()->create(['name' => 'Sam Park', 'email' => 'sam@elsewhere.test']);

        $this->actingAs($this->admin)->get('/users?q=kim')
            ->assertSee('Kim Lee')->assertDontSee('Sam Park')->assertSee('value="kim"', false);

        $this->actingAs($this->admin)->get('/users?q=elsewhere')
            ->assertSee('Sam Park')->assertDontSee('Kim Lee');
    }

    public function test_the_list_is_paged(): void
    {
        User::factory()->count(30)->create();

        $this->actingAs($this->admin)->get('/users')
            ->assertViewHas('users', fn ($users) => $users->count() === 25 && $users->total() === 31)
            ->assertSee('page=2', false);
    }

    public function test_a_new_user_is_created_and_emailed_a_set_password_link(): void
    {
        Notification::fake();

        $this->actingAs($this->admin)
            ->post('/users', $this->form(['email' => ' Kim@Example.com ', 'role' => 'admin', 'password' => 'typed-by-admin']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success');

        $user = User::where('email', 'kim@example.com')->sole();
        $this->assertSame('Kim Lee', $user->name);
        $this->assertSame('Australia/Perth', $user->timezone);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertTrue($user->active);

        Notification::assertSentTo($user, SetPassword::class);
    }

    public function test_the_emailed_link_sets_the_password(): void
    {
        Notification::fake();
        $this->actingAs($this->admin)->post('/users', $this->form(['password' => 'typed-by-admin']));
        $this->post('/logout');
        $user = User::where('email', 'kim@example.com')->sole();

        $this->post('/login', ['email' => 'kim@example.com', 'password' => 'typed-by-admin']);
        $this->assertGuest();

        Notification::assertSentTo($user, SetPassword::class, function (SetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);
            $this->assertSame('Set your '.config('app.name').' password', $mail->subject);
            $this->assertSame(route('password.reset', ['token' => $notification->token, 'email' => $user->email]), $mail->actionUrl);

            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'chosen-by-kim',
                'password_confirmation' => 'chosen-by-kim',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->post('/login', ['email' => 'kim@example.com', 'password' => 'chosen-by-kim']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_new_user_needs_a_name_a_free_email_and_a_real_role(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        $this->post('/users', $this->form(['name' => '']))->assertSessionHasErrors('name');
        $this->post('/users', $this->form(['email' => 'ADA@example.com']))->assertSessionHasErrors('email');
        $this->post('/users', $this->form(['role' => 'owner']))->assertSessionHasErrors('role');
        $this->post('/users', $this->form(['timezone' => 'Nowhere']))->assertSessionHasErrors('timezone');

        $this->assertSame(1, User::count());
        Notification::assertNothingSent();
    }

    public function test_a_mail_failure_still_creates_the_user_and_warns_the_admin(): void
    {
        Exceptions::fake();
        $this->mock(Dispatcher::class)->shouldReceive('send')->andThrow(new RuntimeException('smtp down'));

        $this->actingAs($this->admin)
            ->post('/users', $this->form())
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('warning');

        $this->assertTrue(User::where('email', 'kim@example.com')->exists());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_the_edit_form_shows_the_user(): void
    {
        $user = User::factory()->create(['name' => 'Kim Lee', 'email' => 'kim@example.com', 'timezone' => 'Australia/Perth']);

        $this->actingAs($this->admin)
            ->get("/users/{$user->id}/edit")
            ->assertOk()
            ->assertSee('value="Kim Lee"', false)
            ->assertSee('value="kim@example.com"', false)
            ->assertSee('<option value="Australia/Perth" selected>', false)
            ->assertSee('<option value="member" selected>', false)
            ->assertDontSee('disabled', false);
    }

    public function test_a_user_can_be_edited_promoted_and_deactivated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)
            ->put("/users/{$user->id}", $this->form(['email' => 'Kim@Example.com', 'role' => 'admin', 'active' => '0']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame('Kim Lee', $user->name);
        $this->assertSame('kim@example.com', $user->email);
        $this->assertSame('Australia/Perth', $user->timezone);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertFalse($user->active);
    }

    public function test_an_edit_keeps_the_users_own_email_and_refuses_a_taken_one(): void
    {
        $user = User::factory()->create(['email' => 'kim@example.com']);
        $this->actingAs($this->admin);

        $this->put("/users/{$user->id}", $this->form())->assertSessionHasNoErrors();
        $this->put("/users/{$user->id}", $this->form(['email' => 'ada@example.com']))->assertSessionHasErrors('email');

        $this->assertSame('kim@example.com', $user->fresh()->email);
    }

    public function test_an_admin_cannot_demote_or_deactivate_themselves(): void
    {
        $this->actingAs($this->admin)
            ->get("/users/{$this->admin->id}/edit")
            ->assertSee('name="role" disabled', false)
            ->assertSee('name="active" disabled', false);

        $this->actingAs($this->admin)
            ->put("/users/{$this->admin->id}", $this->form(['name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'role' => 'member', 'active' => '0']))
            ->assertSessionHasNoErrors();

        $this->admin->refresh();
        $this->assertSame('Ada Lovelace', $this->admin->name);
        $this->assertSame(Role::Admin, $this->admin->role);
        $this->assertTrue($this->admin->active);
    }

    public function test_users_cannot_be_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin)->delete("/users/{$user->id}")->assertStatus(405);

        $this->assertNotNull($user->fresh());
    }
}
