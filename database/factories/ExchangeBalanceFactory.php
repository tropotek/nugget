<?php

namespace Database\Factories;

use App\Models\Exchange;
use App\Models\ExchangeBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExchangeBalance>
 */
class ExchangeBalanceFactory extends Factory
{
    /**
     * Define the model's default state: one BTC, all of it available.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'exchange_id' => Exchange::factory(),
            'symbol' => 'BTC',
            'units' => '1',
            'available' => fn (array $attributes) => $attributes['units'],
        ];
    }

    public function of(string $symbol, string $units): static
    {
        return $this->state(fn (array $attributes) => ['symbol' => $symbol, 'units' => $units, 'available' => $units]);
    }
}
