<?php

namespace Tests\Feature\Import;

use App\Enums\Role;
use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\Passes\ExchangesPass;
use App\Import\Passes\UsersPass;
use App\Import\TkbtcSource;
use App\Models\Exchange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The users and exchanges of a made-up tkbtc database.
 */
class UsersAndExchangesPassTest extends TestCase
{
    use RefreshDatabase;
    use TkbtcDatabase;

    private string $hash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTkbtc();
        $this->hash = password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /**
     * A tkbtc user with its auth row.
     *
     * @param  array<string, mixed>  $auth
     */
    private function tkbtcUser(int $id, string $given, string $family, string $email, array $auth = []): void
    {
        $this->tkbtc()->table('user')->insert(['user_id' => $id, 'type' => 'staff', 'given_name' => $given, 'family_name' => $family]);
        $this->tkbtc()->table('auth')->insert($auth + [
            'fkey' => 'App\\Db\\User', 'fid' => $id, 'username' => "user{$id}", 'email' => $email,
            'password' => $this->hash, 'permissions' => 0, 'timezone' => 'Australia/Perth', 'active' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tkbtcExchange(int $id, int $userId, string $driver, array $attributes = []): void
    {
        $this->tkbtc()->table('exchange')->insert($attributes + [
            'exchange_id' => $id, 'user_id' => $userId, 'driver' => $driver, 'username' => 'pat@example.com',
            'api_key' => "key-of-{$id}", 'secret' => "secret-of-{$id}", 'credit' => '1520.45670000', 'active' => 1,
        ]);
    }

    private function import(bool $fresh = false): ImportContext
    {
        $context = new ImportContext(app(TkbtcSource::class));
        (new Importer([app(UsersPass::class), app(ExchangesPass::class)]))->run($context, $fresh);

        return $context;
    }

    // Users

    public function test_a_user_comes_over_with_their_name_email_timezone_role_and_password(): void
    {
        $this->tkbtcUser(2, 'Pat', 'Example', 'Pat@Example.com', ['permissions' => 1]);

        $this->import();

        $user = User::sole();
        $this->assertSame('Pat Example', $user->name);
        $this->assertSame('pat@example.com', $user->email);
        $this->assertSame('Australia/Perth', $user->timezone);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertTrue($user->active);
        // The same password signs them in.
        $this->assertTrue(Hash::check('correct horse', $user->password));
        $this->assertSame($this->hash, DB::table('users')->value('password'));
        $this->post('/login', ['email' => 'pat@example.com', 'password' => 'correct horse'])->assertRedirect(route('dashboard'));
    }

    public function test_a_user_without_the_admin_permission_is_a_member_and_an_inactive_one_stays_inactive(): void
    {
        $this->tkbtcUser(3, 'Mel', '', 'mel@example.com', ['permissions' => 6, 'active' => 0]);

        $this->import();

        $user = User::sole();
        $this->assertSame('Mel', $user->name);
        $this->assertSame(Role::Member, $user->role);
        $this->assertFalse($user->active);
    }

    public function test_a_password_nugget_cannot_check_is_not_carried_over_and_the_operator_is_told(): void
    {
        $this->tkbtcUser(2, 'Pat', 'Example', 'pat@example.com', ['password' => md5('old style')]);
        $this->tkbtcUser(3, '', '', 'nobody@example.com', ['password' => '', 'active' => 0]);

        $context = $this->import();

        $pat = User::where('email', 'pat@example.com')->sole();
        $this->assertFalse(Hash::check('old style', $pat->password));
        $this->assertNotSame(md5('old style'), $pat->password);
        $this->assertSame(
            ['pat@example.com has a password Nugget cannot check, so it was not carried over. They need "Forgot your password?" to sign in.'],
            $context->warnings,
        );
        // With no name, the part of the email before the @. An inactive user cannot sign in anyway, so no warning.
        $this->assertSame('nobody', User::where('email', 'nobody@example.com')->sole()->name);
    }

    public function test_a_timezone_php_does_not_know_becomes_the_default(): void
    {
        $this->tkbtcUser(2, 'Pat', 'Example', 'pat@example.com', ['timezone' => '']);

        $this->import();

        $this->assertSame(User::DEFAULT_TIMEZONE, User::sole()->timezone);
    }

    public function test_a_user_already_in_nugget_is_matched_and_left_as_it_is(): void
    {
        $existing = User::factory()->admin()->create(['email' => 'pat@example.com', 'name' => 'Already here', 'timezone' => 'Australia/Hobart']);
        $before = $existing->password;
        $this->tkbtcUser(2, 'Pat', 'Example', 'PAT@example.com');
        $this->tkbtcExchange(1, 2, 'btcmarkets');

        $context = $this->import();

        $this->assertSame(1, User::count());
        $existing->refresh();
        $this->assertSame(['Already here', 'Australia/Hobart', $before], [$existing->name, $existing->timezone, $existing->password]);
        $this->assertSame($existing->id, $context->newId('user', 2));
        $this->assertContains('pat@example.com is already a Nugget user and was left as it is.', $context->notes);
        // Their exchange comes over to them.
        $this->assertSame($existing->id, Exchange::sole()->user_id);
    }

    public function test_a_user_row_with_no_auth_row_or_no_email_is_not_imported(): void
    {
        $this->tkbtc()->table('user')->insert(['user_id' => 7, 'given_name' => 'Ghost']);
        $this->tkbtcUser(8, 'No', 'Email', '');

        $context = $this->import();

        $this->assertSame(0, User::count());
        $this->assertSame(['tkbtc user 8 has no email and was not imported.'], $context->warnings);
    }

    // Exchanges

    public function test_an_exchange_comes_over_with_its_key_and_secret_encrypted_at_rest(): void
    {
        $this->tkbtcUser(2, 'Pat', 'Example', 'pat@example.com');
        $this->tkbtcExchange(1, 2, 'btcmarkets');

        $this->import();

        $exchange = Exchange::sole();
        $this->assertSame(User::sole()->id, $exchange->user_id);
        $this->assertSame('btcmarkets', $exchange->driver);
        $this->assertSame('BTC Markets', $exchange->label);
        $this->assertSame('key-of-1', $exchange->api_key);
        $this->assertSame('secret-of-1', $exchange->secret);
        $this->assertSame('1520.46', $exchange->aud_balance->toFixed(2));
        $this->assertTrue($exchange->active);
        $this->assertNull($exchange->last_synced_at);

        $stored = DB::table('exchanges')->first();
        $this->assertStringNotContainsString('key-of-1', $stored->api_key);
        $this->assertStringNotContainsString('secret-of-1', $stored->secret);
    }

    public function test_an_exchange_nugget_has_no_driver_for_is_reported_and_skipped(): void
    {
        $this->tkbtcUser(2, 'Pat', 'Example', 'pat@example.com');
        $this->tkbtcExchange(1, 2, 'btcmarkets', ['active' => 0, 'api_key' => '', 'secret' => '']);
        $this->tkbtcExchange(2, 2, 'coinspot');
        $this->tkbtcExchange(3, 2, 'binance');

        $context = $this->import();

        $exchange = Exchange::sole();
        $this->assertSame('btcmarkets', $exchange->driver);
        $this->assertFalse($exchange->active);
        $this->assertNull($exchange->api_key);
        $this->assertSame([
            'The coinspot exchange (tkbtc exchange 2) was not imported: Nugget has no driver for it.',
            'The binance exchange (tkbtc exchange 3) was not imported: Nugget has no driver for it.',
        ], $context->warnings);
        $this->assertNull($context->newId('exchange', 2));
    }

    public function test_an_exchange_whose_user_was_not_imported_is_skipped(): void
    {
        $this->tkbtcExchange(1, 99, 'btcmarkets');

        $context = $this->import();

        $this->assertSame(0, Exchange::count());
        $this->assertSame(['The btcmarkets exchange (tkbtc exchange 1) was not imported: its user was not.'], $context->warnings);
    }

    // Running again

    public function test_running_again_adds_no_user_or_exchange_twice(): void
    {
        $this->tkbtcUser(2, 'Pat', 'Example', 'pat@example.com');
        $this->tkbtcExchange(1, 2, 'btcmarkets');
        $this->tkbtcExchange(2, 2, 'coinspot');
        $this->import();
        User::sole()->update(['name' => 'Renamed in Nugget']);

        $this->artisan('nugget:import-tkbtc')
            ->expectsOutputToContain('The coinspot exchange (tkbtc exchange 2) was not imported')
            ->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame(1, Exchange::count());
        $this->assertSame('Renamed in Nugget', User::sole()->name);
    }

    public function test_a_fresh_run_replaces_the_exchanges_and_keeps_the_users(): void
    {
        $this->tkbtcUser(2, 'Pat', 'Example', 'pat@example.com');
        $this->tkbtcExchange(1, 2, 'btcmarkets');
        $this->import();
        $user = User::sole();
        $first = Exchange::sole();
        $mine = Exchange::factory()->for($user)->create(['label' => 'Added by hand']);

        $this->import(fresh: true);

        // Users are never deleted: the same one, matched again.
        $this->assertSame($user->id, User::sole()->id);
        $this->assertModelMissing($first);
        $this->assertModelExists($mine);
        $this->assertSame(2, Exchange::count());
    }

    public function test_the_command_counts_what_tkbtc_has(): void
    {
        $this->tkbtcUser(2, 'Pat', 'Example', 'pat@example.com');
        $this->tkbtcUser(3, 'Mel', '', 'mel@example.com');
        $this->tkbtcExchange(1, 2, 'btcmarkets');
        $this->tkbtcExchange(2, 2, 'coinspot');

        $this->artisan('nugget:import-tkbtc', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Exchange::count());
    }
}
