<?php

namespace Database\Factories;

use App\Enums\AssetClass;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /**
     * Define the model's default state: a crypto asset with no price source.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Bitcoin',
            'symbol' => 'BTC',
            'class' => AssetClass::Crypto,
            'unit' => 'BTC',
            'in_total' => true,
        ];
    }

    public function crypto(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Bitcoin',
            'symbol' => 'BTC',
            'class' => AssetClass::Crypto,
            'unit' => 'BTC',
        ]);
    }

    /**
     * Gold by the troy ounce, priced by the feed.
     */
    public function metal(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Gold bullion',
            'symbol' => 'XAU',
            'class' => AssetClass::Metal,
            'unit' => 'oz',
            'exchange_id' => null,
            'market_id' => null,
            'feed_symbol' => 'XAU/AUD',
        ]);
    }

    /**
     * Something with no price source.
     */
    public function other(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Coin collection',
            'symbol' => 'COINS',
            'class' => AssetClass::Other,
            'unit' => 'item',
            'exchange_id' => null,
            'market_id' => null,
            'feed_symbol' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['archived' => true]);
    }

    /**
     * Priced by a market on this exchange, and owned by the exchange's user.
     */
    public function onExchange(Exchange $exchange, string $marketId): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $exchange->user_id,
            'exchange_id' => $exchange->id,
            'market_id' => $marketId,
            'feed_symbol' => null,
        ]);
    }
}
