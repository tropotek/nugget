<?php

namespace Database\Factories;

use App\Models\Exchange;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Exchange>
 */
class ExchangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'driver' => 'btcmarkets',
            'label' => 'BTC Markets',
            'api_key' => Str::random(32),
            'secret' => Str::random(64),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }

    /**
     * An exchange whose last sync worked and found this much AUD.
     */
    public function synced(string $audBalance = '0.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'aud_balance' => $audBalance,
            'last_synced_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * An exchange whose last sync failed with this error.
     */
    public function failed(string $error = 'Invalid API key'): static
    {
        return $this->state(fn (array $attributes) => ['last_error' => $error]);
    }
}
