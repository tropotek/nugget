<?php

namespace Database\Factories;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Transaction;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state: a manual buy of one unit for $100.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'type' => TransactionType::Buy,
            'occurred_at' => now()->subDay(),
            'units' => '1',
            'unit_price' => '100',
            'fee' => '0',
            'total' => '100',
            'source' => TransactionSource::Manual,
            'estimated' => false,
        ];
    }

    /**
     * The total is the cost with the fee.
     */
    public function buy(string $units, string $unitPrice, string $fee = '0'): static
    {
        return $this->priced(TransactionType::Buy, $units, $unitPrice, $fee, Decimal::of($units)->mul($unitPrice)->add($fee));
    }

    /**
     * The total is the proceeds less the fee.
     */
    public function sell(string $units, string $unitPrice, string $fee = '0'): static
    {
        return $this->priced(TransactionType::Sell, $units, $unitPrice, $fee, Decimal::of($units)->mul($unitPrice)->sub($fee));
    }

    /**
     * Acquired at no cost.
     */
    public function found(string $units): static
    {
        return $this->priced(TransactionType::Found, $units, '0', '0', Decimal::zero());
    }

    /**
     * Costed at the market price on the day.
     */
    public function reward(string $units, string $unitPrice = '0'): static
    {
        return $this->priced(TransactionType::Reward, $units, $unitPrice, '0', Decimal::of($units)->mul($unitPrice));
    }

    public function transferIn(string $units): static
    {
        return $this->priced(TransactionType::TransferIn, $units, '0', '0', Decimal::zero());
    }

    public function transferOut(string $units): static
    {
        return $this->priced(TransactionType::TransferOut, $units, '0', '0', Decimal::zero());
    }

    /**
     * A correction: positive units add to the holding, negative take away.
     */
    public function adjust(string $units): static
    {
        return $this->priced(TransactionType::Adjust, $units, '0', '0', Decimal::zero());
    }

    /**
     * A trade fetched from an exchange, with the exchange's own id for it.
     */
    public function fromExchange(string $externalId): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => TransactionSource::Exchange,
            'external_id' => $externalId,
        ]);
    }

    public function estimated(): static
    {
        return $this->state(fn (array $attributes) => ['estimated' => true]);
    }

    private function priced(TransactionType $type, string $units, string $unitPrice, string $fee, Decimal $total): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'units' => $units,
            'unit_price' => $unitPrice,
            'fee' => $fee,
            'total' => $total,
        ]);
    }
}
