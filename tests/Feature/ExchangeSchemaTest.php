<?php

namespace Tests\Feature;

use App\Models\Exchange;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExchangeSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_exchanges_table_has_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'id', 'user_id', 'driver', 'label', 'api_key', 'secret', 'aud_balance', 'active',
                'last_synced_at', 'last_error', 'last_sync_note', 'trade_cursor', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('exchanges'),
        );
    }

    public function test_an_exchange_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $exchange = $user->exchanges()->create(['driver' => 'btcmarkets', 'label' => 'BTC Markets', 'api_key' => 'k', 'secret' => 's']);

        $this->assertTrue($exchange->user->is($user));
        $this->assertTrue($user->exchanges->first()->is($exchange));
        $this->assertSame($user->id, $exchange->ownerId());
    }

    public function test_a_new_exchange_is_active_with_no_balance_and_no_sync(): void
    {
        $exchange = User::factory()->create()->exchanges()
            ->create(['driver' => 'kraken', 'label' => 'Kraken', 'api_key' => 'k', 'secret' => 's'])
            ->fresh();

        $this->assertTrue($exchange->active);
        $this->assertInstanceOf(Decimal::class, $exchange->aud_balance);
        $this->assertTrue($exchange->aud_balance->isZero());
        $this->assertNull($exchange->last_synced_at);
        $this->assertNull($exchange->last_error);
    }

    public function test_the_key_and_secret_are_encrypted_at_rest(): void
    {
        $exchange = Exchange::factory()->create(['api_key' => 'my-api-key', 'secret' => 'my-secret']);
        $stored = DB::table('exchanges')->find($exchange->id);

        $this->assertStringNotContainsString('my-api-key', $stored->api_key);
        $this->assertStringNotContainsString('my-secret', $stored->secret);
        $this->assertSame('my-api-key', Crypt::decryptString($stored->api_key));
        $this->assertSame('my-secret', Crypt::decryptString($stored->secret));

        $this->assertSame('my-api-key', $exchange->fresh()->api_key);
        $this->assertSame('my-secret', $exchange->fresh()->secret);
    }

    public function test_the_key_and_secret_are_left_out_of_arrays_and_json(): void
    {
        $exchange = Exchange::factory()->create(['api_key' => 'my-api-key', 'secret' => 'my-secret'])->fresh();

        $this->assertArrayNotHasKey('api_key', $exchange->toArray());
        $this->assertArrayNotHasKey('secret', $exchange->toArray());
        $this->assertStringNotContainsString('my-api-key', $exchange->toJson());
        $this->assertStringNotContainsString('my-secret', $exchange->toJson());
    }

    public function test_the_aud_balance_is_a_decimal_stored_to_two_places(): void
    {
        $exchange = Exchange::factory()->create();
        $exchange->forceFill(['aud_balance' => '1234.5'])->save();

        $this->assertSame('1234.50', DB::table('exchanges')->find($exchange->id)->aud_balance);
        $this->assertSame('1,234.50', $exchange->fresh()->aud_balance->format());
    }

    public function test_the_last_sync_time_is_a_date(): void
    {
        $exchange = Exchange::factory()->create();
        $exchange->forceFill(['last_synced_at' => '2025-07-01 02:30:00'])->save();

        $this->assertInstanceOf(Carbon::class, $exchange->fresh()->last_synced_at);
        $this->assertSame('2025-07-01 02:30:00', $exchange->fresh()->last_synced_at->toDateTimeString());
    }

    public function test_the_owner_and_the_sync_results_cannot_be_mass_assigned(): void
    {
        $exchange = new Exchange([
            'driver' => 'kraken', 'label' => 'Kraken',
            'user_id' => 99, 'aud_balance' => '5000', 'last_synced_at' => now(), 'last_error' => 'Nope',
        ]);

        $this->assertNull($exchange->user_id);
        $this->assertTrue($exchange->aud_balance->isZero());
        $this->assertNull($exchange->last_synced_at);
        $this->assertNull($exchange->last_error);
    }

    public function test_the_factory_makes_an_exchange_and_its_states(): void
    {
        $exchange = Exchange::factory()->create()->fresh();

        $this->assertNotNull($exchange->user);
        $this->assertNotSame('', $exchange->driver);
        $this->assertNotSame('', $exchange->label);
        $this->assertNotSame('', $exchange->api_key);
        $this->assertTrue($exchange->active);

        $this->assertFalse(Exchange::factory()->inactive()->create()->active);

        $synced = Exchange::factory()->synced('250.75')->create()->fresh();
        $this->assertTrue($synced->aud_balance->equals('250.75'));
        $this->assertNotNull($synced->last_synced_at);

        $this->assertSame('Bad key', Exchange::factory()->failed('Bad key')->create()->last_error);
    }

    public function test_only_the_owner_may_work_with_an_exchange(): void
    {
        $exchange = Exchange::factory()->create();

        $this->assertTrue(Gate::forUser($exchange->user)->allows('update', $exchange));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->create())->allows('view', $exchange));
    }

    public function test_a_user_with_an_exchange_cannot_be_deleted(): void
    {
        $exchange = Exchange::factory()->create();

        $this->expectException(QueryException::class);

        $exchange->user->delete();
    }
}
