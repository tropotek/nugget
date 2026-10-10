<?php

namespace Database\Factories;

use App\Models\Parcel;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Parcel>
 */
class ParcelFactory extends Factory
{
    /**
     * Define the model's default state: one untouched unit that cost $100,
     * acquired by a new buy on the parcel's asset.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_id' => function (array $attributes) {
                $assetId = value($attributes['asset_id'] ?? null);

                return $assetId ? Transaction::factory()->state(['asset_id' => $assetId]) : Transaction::factory();
            },
            'acquired_at' => fn (array $attributes) => Transaction::find($attributes['transaction_id'])->occurred_at,
            'units' => '1',
            'units_remaining' => '1',
            'cost_base' => '100',
            'estimated' => false,
        ];
    }

    /**
     * The whole parcel of an acquisition: its units, its total as the cost base and its time.
     */
    public function acquiredBy(Transaction $transaction): static
    {
        return $this->state(fn (array $attributes) => [
            'asset_id' => $transaction->asset_id,
            'transaction_id' => $transaction->id,
            'acquired_at' => $transaction->occurred_at,
            'units' => $transaction->units,
            'units_remaining' => $transaction->units,
            'cost_base' => $transaction->total,
            'estimated' => $transaction->estimated,
        ]);
    }

    public function remaining(string $units): static
    {
        return $this->state(fn (array $attributes) => ['units_remaining' => $units]);
    }

    public function used(): static
    {
        return $this->remaining('0');
    }

    public function estimated(): static
    {
        return $this->state(fn (array $attributes) => ['estimated' => true]);
    }
}
