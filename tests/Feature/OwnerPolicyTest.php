<?php

namespace Tests\Feature;

use App\Models\Contracts\Owned;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class OwnerPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function thingOwnedBy(User $user): Model
    {
        return new OwnedThing(['user_id' => $user->id]);
    }

    public function test_the_owner_can_view_change_and_delete_their_own(): void
    {
        $owner = User::factory()->create();
        $thing = $this->thingOwnedBy($owner);

        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($owner)->allows($ability, $thing), $ability);
        }
    }

    public function test_another_user_cannot_even_as_an_admin(): void
    {
        $thing = $this->thingOwnedBy(User::factory()->create());

        foreach ([User::factory()->create(), User::factory()->admin()->create()] as $other) {
            foreach (['view', 'update', 'delete'] as $ability) {
                $this->assertFalse(Gate::forUser($other)->allows($ability, $thing), $ability);
            }
        }
    }

    public function test_any_user_can_list_and_create_their_own(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', OwnedThing::class));
        $this->assertTrue(Gate::forUser($user)->allows('create', OwnedThing::class));
    }

    public function test_a_model_without_an_owner_is_not_covered(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('view', new UnownedThing));
    }

    public function test_the_user_policy_still_applies_to_users(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('viewAny', User::class));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('viewAny', User::class));
    }
}

class OwnedThing extends Model implements Owned
{
    protected $guarded = [];

    public function ownerId(): int
    {
        return (int) $this->user_id;
    }
}

class UnownedThing extends Model {}
