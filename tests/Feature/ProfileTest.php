<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Kim Lee',
            'email' => 'kim@example.com',
            'timezone' => 'Australia/Perth',
        ]);
    }

    /**
     * @param  array<string, string>  $changes
     * @return array<string, string>
     */
    private function details(array $changes = []): array
    {
        return $changes + ['name' => 'Kim Lee', 'email' => 'kim@example.com', 'timezone' => 'Australia/Perth'];
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/profile')->assertRedirect(route('login'));
        $this->patch('/profile', $this->details(['name' => 'Someone Else']))->assertRedirect(route('login'));

        $this->assertSame('Kim Lee', $this->user->fresh()->name);
    }

    public function test_the_page_shows_the_users_details(): void
    {
        $this->actingAs($this->user)
            ->get('/profile')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Profile</h4>', false)
            ->assertSee('value="Kim Lee"', false)
            ->assertSee('value="kim@example.com"', false)
            ->assertSee('<option value="Australia/Perth" selected>', false)
            ->assertSee('<option value="Australia/Melbourne">', false);
    }

    public function test_the_top_bar_links_to_the_profile(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertSee('href="'.route('profile.edit').'"', false);
    }

    public function test_the_name_and_timezone_can_be_changed(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['name' => 'Kim Nguyen', 'timezone' => 'Europe/London']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success', 'Profile saved.');

        $this->user->refresh();
        $this->assertSame('Kim Nguyen', $this->user->name);
        $this->assertSame('Europe/London', $this->user->timezone);
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame('Kim Lee', $this->user->fresh()->name);
    }

    public function test_the_timezone_must_be_a_real_one(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['timezone' => 'Australia/Nowhere']))
            ->assertSessionHasErrors('timezone');

        $this->assertSame('Australia/Perth', $this->user->fresh()->timezone);
    }

    public function test_changing_the_email_needs_the_current_password(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['email' => 'new@example.com']))
            ->assertSessionHasErrors('current_password');

        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['email' => 'new@example.com', 'current_password' => 'not-it']))
            ->assertSessionHasErrors('current_password');

        $this->assertSame('kim@example.com', $this->user->fresh()->email);
    }

    public function test_the_email_can_be_changed_and_is_stored_in_lower_case(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['email' => ' New@Example.COM ', 'current_password' => 'password']))
            ->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $this->user->fresh()->email);
    }

    public function test_the_same_email_in_another_case_needs_no_password(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['email' => 'KIM@example.com', 'name' => 'Kim Nguyen']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Kim Nguyen', $this->user->fresh()->name);
        $this->assertSame('kim@example.com', $this->user->fresh()->email);
    }

    public function test_the_email_cannot_be_one_another_user_has(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['email' => 'Taken@example.com', 'current_password' => 'password']))
            ->assertSessionHasErrors('email');

        $this->assertSame('kim@example.com', $this->user->fresh()->email);
    }

    public function test_the_role_cannot_be_changed_from_the_profile(): void
    {
        $this->actingAs($this->user)
            ->patch('/profile', $this->details(['role' => 'admin']))
            ->assertSessionHasNoErrors();

        $this->assertSame(Role::Member, $this->user->fresh()->role);
    }

    public function test_the_password_can_be_changed(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'password',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success', 'Password changed.');

        $this->assertTrue(Hash::check('a-new-password', $this->user->fresh()->password));
    }

    public function test_changing_the_password_needs_the_current_password(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'not-it',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->actingAs($this->user)
            ->put('/profile/password', [
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'current_password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed_and_long_enough(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'password',
                'password' => 'a-new-password',
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
    }

    public function test_changing_the_password_ends_remember_me_on_other_devices(): void
    {
        $before = $this->user->remember_token;

        $this->actingAs($this->user)->put('/profile/password', [
            'current_password' => 'password',
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ]);

        $this->assertNotSame($before, $this->user->fresh()->remember_token);
    }

    public function test_a_password_error_shows_on_the_password_form(): void
    {
        $this->actingAs($this->user)
            ->followingRedirects()
            ->from('/profile')
            ->put('/profile/password', [
                'current_password' => 'not-it',
                'password' => 'a-new-password',
                'password_confirmation' => 'a-new-password',
            ])
            ->assertSee(__('validation.current_password'));
    }
}
