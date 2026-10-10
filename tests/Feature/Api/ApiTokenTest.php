<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Personal access tokens: making and revoking them on the profile page, and
 * what the API does with one. "Now" is midday in Melbourne on 1 August 2025.
 */
class ApiTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-08-01 02:00:00');
        $this->user = User::factory()->create(['name' => 'Pat Example', 'email' => 'pat@example.com', 'timezone' => 'Australia/Melbourne']);
    }

    /**
     * A token of the user's with the read ability, as its text.
     */
    private function token(?User $user = null, string $name = 'Claude', array $abilities = ['read']): string
    {
        return ($user ?? $this->user)->createToken($name, $abilities)->plainTextToken;
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    // The profile page

    public function test_the_profile_page_has_a_section_for_tokens(): void
    {
        $this->actingAs($this->user)->get('/profile')
            ->assertOk()
            ->assertSeeInOrder(['id="api-tokens"', 'API tokens', url('/api/v1'), 'Make a token', 'No tokens yet.'], false)
            ->assertDontSee('new-api-token');
    }

    public function test_a_token_is_made_with_a_name_and_the_read_ability_and_shown_once(): void
    {
        $response = $this->actingAs($this->user)->post('/profile/tokens', ['token_name' => 'Claude'])
            ->assertRedirect(route('profile.edit').'#api-tokens')
            ->assertSessionHas('api_token');

        $text = session('api_token');
        $stored = $this->user->tokens()->sole();

        $this->assertSame('Claude', $stored->name);
        $this->assertSame(['read'], $stored->abilities);
        $this->assertNull($stored->expires_at);

        // The page after shows it; the one after that does not.
        $this->get('/profile')->assertSeeInOrder(['id="new-api-token"', 'Copy it now: it is not shown again.', e($text)], false);
        $this->get('/profile')->assertDontSee('new-api-token')->assertDontSee($text)->assertSee('Claude');

        // And it works.
        $this->getJson('/api/v1/me', $this->bearer($text))->assertOk();
    }

    public function test_only_the_hash_of_a_token_is_stored(): void
    {
        $text = $this->token();
        $secret = explode('|', $text)[1];

        $row = DB::table('personal_access_tokens')->first();

        $this->assertSame(hash('sha256', $secret), $row->token);
        $this->assertStringNotContainsString($secret, json_encode($row));
    }

    public function test_a_token_needs_a_name(): void
    {
        $this->actingAs($this->user)->from('/profile')->post('/profile/tokens', ['token_name' => ''])
            ->assertRedirect('/profile')
            ->assertSessionHasErrorsIn('apiToken', 'token_name');
        $this->post('/profile/tokens', ['token_name' => str_repeat('a', 101)])->assertSessionHasErrorsIn('apiToken', 'token_name');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_tokens_are_listed_with_when_they_were_made_and_last_used(): void
    {
        $used = $this->user->createToken('Claude', ['read'])->accessToken;
        $used->forceFill(['created_at' => '2025-07-01 02:00:00', 'last_used_at' => '2025-07-31 01:30:00'])->save();
        $this->token(name: 'Spreadsheet');
        $this->token(User::factory()->create(), 'Somebody else');

        $this->actingAs($this->user)->get('/profile')
            ->assertSeeInOrder(['Spreadsheet', '1 Aug 2025, 12:00pm', 'Never', 'Claude', '1 Jul 2025, 12:00pm', '31 Jul 2025, 11:30am'])
            ->assertSee('action="'.route('profile.tokens.destroy', $used).'"', false)
            ->assertDontSee('Somebody else');
    }

    public function test_a_token_is_revoked_and_stops_working_at_once(): void
    {
        $text = $this->token();
        $this->getJson('/api/v1/me', $this->bearer($text))->assertOk();

        $this->actingAs($this->user)->delete(route('profile.tokens.destroy', $this->user->tokens()->sole()))
            ->assertRedirect(route('profile.edit').'#api-tokens')
            ->assertSessionHas('success', 'Revoked the token "Claude".');

        $this->assertSame(0, PersonalAccessToken::count());

        auth()->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $this->getJson('/api/v1/me', $this->bearer($text))->assertUnauthorized();
    }

    public function test_another_users_token_cannot_be_revoked(): void
    {
        $other = User::factory()->create();
        $this->token($other);

        $this->actingAs($this->user)->delete(route('profile.tokens.destroy', $other->tokens()->sole()))->assertNotFound();

        $this->assertSame(1, $other->tokens()->count());
    }

    public function test_a_guest_can_neither_make_nor_revoke_a_token(): void
    {
        $this->token();

        $this->post('/profile/tokens', ['token_name' => 'Claude'])->assertRedirect(route('login'));
        $this->delete(route('profile.tokens.destroy', $this->user->tokens()->sole()))->assertRedirect(route('login'));

        $this->assertSame(1, PersonalAccessToken::count());
    }

    // The API

    public function test_a_valid_token_reaches_the_api_as_its_own_user(): void
    {
        $this->token(User::factory()->create(['name' => 'Somebody else']));

        $this->getJson('/api/v1/me', $this->bearer($this->token()))
            ->assertOk()
            ->assertExactJson(['data' => ['name' => 'Pat Example', 'email' => 'pat@example.com', 'timezone' => 'Australia/Melbourne', 'token' => 'Claude']]);
    }

    public function test_a_token_records_when_it_was_last_used(): void
    {
        $text = $this->token();
        $this->assertNull($this->user->tokens()->sole()->last_used_at);

        $this->getJson('/api/v1/me', $this->bearer($text))->assertOk();

        $this->assertSame('2025-08-01 02:00:00', $this->user->tokens()->sole()->last_used_at->toDateTimeString());
    }

    public function test_a_request_with_no_token_is_refused_in_json(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);

        // A browser's request gets JSON too, not a redirect to the login page.
        $this->get('/api/v1/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_token_that_does_not_exist_is_refused(): void
    {
        $text = $this->token();

        $this->getJson('/api/v1/me', $this->bearer('nonsense'))->assertUnauthorized();
        $this->getJson('/api/v1/me', $this->bearer($text.'x'))->assertUnauthorized();
        $this->getJson('/api/v1/me', $this->bearer('999|'.explode('|', $text)[1]))->assertUnauthorized();
    }

    public function test_a_revoked_token_is_refused(): void
    {
        $text = $this->token();
        $this->user->tokens()->delete();

        $this->getJson('/api/v1/me', $this->bearer($text))->assertUnauthorized();
    }

    public function test_the_token_of_a_deactivated_user_is_refused_like_one_that_does_not_exist(): void
    {
        $text = $this->token();
        $this->user->forceFill(['active' => false])->save();

        $this->getJson('/api/v1/me', $this->bearer($text))->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_token_without_the_read_ability_is_forbidden(): void
    {
        $this->getJson('/api/v1/me', $this->bearer($this->token(abilities: ['write'])))->assertForbidden();
        $this->getJson('/api/v1/me', $this->bearer($this->token(abilities: [])))->assertForbidden();
    }

    public function test_a_signed_in_browser_session_is_not_a_token(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_the_api_only_answers_get(): void
    {
        $bearer = $this->bearer($this->token());

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->json($method, '/api/v1/me', [], $bearer)->assertStatus(405)->assertJsonStructure(['message']);
        }
    }

    public function test_an_unknown_api_path_is_not_found_in_json(): void
    {
        $this->get('/api/v1/nothing-here', $this->bearer($this->token()))->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_a_token_is_limited_to_so_many_requests_a_minute(): void
    {
        config(['nugget.api.per_minute' => 3]);
        $mine = $this->bearer($this->token());
        $theirs = $this->bearer($this->token(User::factory()->create()));

        foreach (range(1, 3) as $request) {
            $this->getJson('/api/v1/me', $mine)->assertOk();
        }

        $this->getJson('/api/v1/me', $mine)->assertStatus(429)->assertJsonStructure(['message']);

        // Another token has its own allowance, and a minute later this one has again.
        auth()->forgetGuards();
        $this->getJson('/api/v1/me', $theirs)->assertOk();

        $this->travel(61)->seconds();
        auth()->forgetGuards();
        $this->getJson('/api/v1/me', $mine)->assertOk();
    }
}
