<?php

namespace Database\Factories;

use App\Models\Disposal;
use App\Models\Parcel;
use App\Models\Transaction;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Disposal>
 */
class DisposalFactory extends Factory
{
    /**
     * Define the model's default state: one unit that cost $100, sold for
     * $150 by a new sale on the parcel's asset.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parcel_id' => Parcel::factory(),
            'transaction_id' => fn (array $attributes) => Transaction::factory()
                ->sell('1', '150')
                ->state(['asset_id' => Parcel::find($attributes['parcel_id'])->asset_id]),
            'units' => '1',
            'cost_base' => '100',
            'proceeds' => '150',
            'gain' => '50',
            'long_held' => false,
        ];
    }

    /**
     * The gain is worked out: the proceeds less the cost base.
     */
    public function amounts(string $units, string $costBase, string $proceeds): static
    {
        return $this->state(fn (array $attributes) => [
            'units' => $units,
            'cost_base' => $costBase,
            'proceeds' => $proceeds,
            'gain' => Decimal::of($proceeds)->sub($costBase),
        ]);
    }

    public function longHeld(): static
    {
        return $this->state(fn (array $attributes) => ['long_held' => true]);
    }
}
