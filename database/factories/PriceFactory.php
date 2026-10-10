<?php

namespace Database\Factories;

use App\Enums\PriceResolution;
use App\Models\Asset;
use App\Models\Price;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
class PriceFactory extends Factory
{
    /**
     * Define the model's default state: an intraday price fetched now, $100 to sell and $101 to buy.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'at' => now()->startOfSecond(),
            'bid' => '100',
            'ask' => '101',
            'resolution' => PriceResolution::Intraday,
        ];
    }

    /**
     * The day row of a date, given as 2025-06-30.
     */
    public function day(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'at' => Price::day($date),
            'resolution' => PriceResolution::Day,
        ]);
    }

    /**
     * An intraday row fetched at this moment.
     */
    public function at(DateTimeInterface|string $moment): static
    {
        return $this->state(fn (array $attributes) => [
            'at' => $moment,
            'resolution' => PriceResolution::Intraday,
        ]);
    }

    public function quoted(string $bid, string $ask): static
    {
        return $this->state(fn (array $attributes) => ['bid' => $bid, 'ask' => $ask]);
    }
}
