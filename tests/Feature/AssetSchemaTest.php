<?php

namespace Tests\Feature;

use App\Enums\AssetClass;
use App\Enums\PricedBy;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssetSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_assets_table_has_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            [
                'id', 'user_id', 'name', 'symbol', 'class', 'unit', 'exchange_id', 'market_id', 'feed_symbol',
                'in_total', 'notes', 'created_at', 'updated_at',
            ],
            Schema::getColumnListing('assets'),
        );
    }

    public function test_an_asset_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $asset = $user->assets()->create(['name' => 'Bitcoin', 'symbol' => 'BTC', 'class' => 'crypto', 'unit' => 'BTC']);

        $this->assertTrue($asset->user->is($user));
        $this->assertTrue($user->assets->first()->is($asset));
        $this->assertSame($user->id, $asset->ownerId());
    }

    public function test_a_new_asset_counts_toward_the_total_and_has_no_price(): void
    {
        $asset = User::factory()->create()->assets()
            ->create(['name' => 'Coin collection', 'symbol' => 'COINS', 'class' => 'other', 'unit' => 'item'])
            ->fresh();

        $this->assertTrue($asset->in_total);
        $this->assertNull($asset->exchange);
        $this->assertNull($asset->market_id);
        $this->assertNull($asset->feed_symbol);
        $this->assertNull($asset->notes);
        $this->assertSame(PricedBy::Nothing, $asset->pricedBy());
    }

    public function test_the_class_is_stored_as_its_value_and_read_as_the_enum(): void
    {
        $asset = Asset::factory()->create(['class' => AssetClass::Metal]);

        $this->assertDatabaseHas('assets', ['id' => $asset->id, 'class' => 'metal']);
        $this->assertSame(AssetClass::Metal, $asset->fresh()->class);
    }

    public function test_each_class_has_a_label(): void
    {
        $this->assertSame(['crypto', 'metal', 'other'], array_column(AssetClass::cases(), 'value'));
        $this->assertSame('Crypto', AssetClass::Crypto->label());
        $this->assertSame('Metal', AssetClass::Metal->label());
        $this->assertSame('Other', AssetClass::Other->label());
    }

    public function test_an_asset_can_be_priced_by_a_market_on_one_of_the_users_exchanges(): void
    {
        $exchange = Exchange::factory()->create();
        $asset = $exchange->user->assets()->create([
            'name' => 'Bitcoin', 'symbol' => 'BTC', 'class' => 'crypto', 'unit' => 'BTC',
            'exchange_id' => $exchange->id, 'market_id' => 'BTC/AUD',
        ])->fresh();

        $this->assertSame(PricedBy::Exchange, $asset->pricedBy());
        $this->assertTrue($asset->exchange->is($exchange));
        $this->assertTrue($exchange->assets->first()->is($asset));
    }

    public function test_an_asset_can_be_priced_by_a_feed_symbol(): void
    {
        $asset = User::factory()->create()->assets()->create([
            'name' => 'Gold', 'symbol' => 'XAU', 'class' => 'metal', 'unit' => 'oz', 'feed_symbol' => 'XAU/AUD',
        ])->fresh();

        $this->assertSame(PricedBy::Feed, $asset->pricedBy());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function mixedUpPriceSources(): array
    {
        return [
            'a market with no exchange' => [['market_id' => 'BTC/AUD']],
            'an exchange with no market' => [['exchange_id' => 'mine']],
            'a market and a feed' => [['exchange_id' => 'mine', 'market_id' => 'BTC/AUD', 'feed_symbol' => 'XAU/AUD']],
            'another users exchange' => [['exchange_id' => 'theirs', 'market_id' => 'BTC/AUD']],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     */
    #[DataProvider('mixedUpPriceSources')]
    public function test_a_mixed_up_price_source_is_refused(array $source): void
    {
        $user = User::factory()->create();
        $exchanges = [
            'mine' => Exchange::factory()->for($user)->create()->id,
            'theirs' => Exchange::factory()->create()->id,
        ];

        if (isset($source['exchange_id'])) {
            $source['exchange_id'] = $exchanges[$source['exchange_id']];
        }

        $this->expectException(LogicException::class);

        $user->assets()->create(['name' => 'Bitcoin', 'symbol' => 'BTC', 'class' => 'crypto', 'unit' => 'BTC'] + $source);
    }

    public function test_the_owner_cannot_be_mass_assigned(): void
    {
        $this->assertNull((new Asset(['name' => 'Bitcoin', 'user_id' => 99]))->user_id);
    }

    public function test_the_factory_has_a_state_for_each_class(): void
    {
        $crypto = Asset::factory()->crypto()->create()->fresh();
        $this->assertSame(AssetClass::Crypto, $crypto->class);
        $this->assertNotNull($crypto->user);

        $metal = Asset::factory()->metal()->create()->fresh();
        $this->assertSame(AssetClass::Metal, $metal->class);
        $this->assertSame('oz', $metal->unit);
        $this->assertSame(PricedBy::Feed, $metal->pricedBy());

        $other = Asset::factory()->other()->create()->fresh();
        $this->assertSame(AssetClass::Other, $other->class);
        $this->assertSame(PricedBy::Nothing, $other->pricedBy());
    }

    public function test_the_factory_can_put_an_asset_on_an_exchange(): void
    {
        $exchange = Exchange::factory()->create();

        $asset = Asset::factory()->onExchange($exchange, 'ADA/AUD')->create()->fresh();

        $this->assertTrue($asset->user->is($exchange->user));
        $this->assertTrue($asset->exchange->is($exchange));
        $this->assertSame('ADA/AUD', $asset->market_id);
        $this->assertSame(PricedBy::Exchange, $asset->pricedBy());
    }

    public function test_only_the_owner_may_work_with_an_asset(): void
    {
        $asset = Asset::factory()->create();

        $this->assertTrue(Gate::forUser($asset->user)->allows('update', $asset));
        $this->assertFalse(Gate::forUser(User::factory()->admin()->create())->allows('view', $asset));
    }

    public function test_an_exchange_that_prices_an_asset_cannot_be_deleted(): void
    {
        $exchange = Exchange::factory()->create();
        Asset::factory()->onExchange($exchange, 'BTC/AUD')->create();

        $this->expectException(QueryException::class);

        $exchange->delete();
    }

    public function test_a_user_with_an_asset_cannot_be_deleted(): void
    {
        $asset = Asset::factory()->create();

        $this->expectException(QueryException::class);

        $asset->user->delete();
    }
}
