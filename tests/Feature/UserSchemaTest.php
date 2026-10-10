<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_users_table_has_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'password', 'role', 'active', 'timezone', 'remember_token', 'created_at', 'updated_at'],
            Schema::getColumnListing('users'),
        );
    }

    public function test_a_new_user_is_a_member_in_the_default_timezone(): void
    {
        $user = User::create(['name' => 'Kim', 'email' => 'kim@example.com', 'password' => 'secret-pass']);

        $this->assertSame(Role::Member, $user->role);
        $this->assertSame(User::DEFAULT_TIMEZONE, $user->timezone);
        $this->assertSame(Role::Member, $user->fresh()->role);
        $this->assertSame(User::DEFAULT_TIMEZONE, $user->fresh()->timezone);
    }

    public function test_the_role_is_stored_as_its_value_and_read_as_the_enum(): void
    {
        $user = User::factory()->admin()->create();

        $this->assertSame(Role::Admin, $user->fresh()->role);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'admin']);
    }

    public function test_the_role_cannot_be_mass_assigned(): void
    {
        $user = User::create([
            'name' => 'Kim', 'email' => 'kim@example.com', 'password' => 'secret-pass', 'role' => 'admin',
        ]);

        $this->assertSame(Role::Member, $user->fresh()->role);
    }

    public function test_the_factory_makes_members_unless_asked_for_an_admin(): void
    {
        $this->assertFalse(User::factory()->create()->isAdmin());
        $this->assertTrue(User::factory()->admin()->create()->isAdmin());
    }

    public function test_the_admin_ability_is_for_admins_only(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('admin'));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('admin'));
        $this->assertFalse(Gate::allows('admin'));
    }

    public function test_each_role_has_a_label(): void
    {
        $this->assertSame('Admin', Role::Admin->label());
        $this->assertSame('Member', Role::Member->label());
    }
}
