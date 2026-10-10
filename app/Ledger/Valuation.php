<?php

namespace App\Ledger;

use App\Models\Price;
use App\Support\Decimal;

/**
 * A holding at the asset's latest price: what its units would sell for now
 * (the bid), against what they cost. Every figure is null when the asset has
 * no price.
 */
final readonly class Valuation
{
    public function __construct(public Holding $holding, public ?Price $price) {}

    public function isPriced(): bool
    {
        return $this->price !== null;
    }

    /**
     * The AUD market value of the units held.
     */
    public function value(): ?Decimal
    {
        return $this->price ? $this->holding->units->mul($this->price->bid)->round(Decimal::AUD_SCALE) : null;
    }

    /**
     * The unrealised gain: the value less the cost base. Negative for a loss.
     */
    public function gain(): ?Decimal
    {
        return $this->value()?->sub($this->holding->costBase);
    }

    /**
     * The gain as a percentage of the cost base, or null when there is no cost to compare it with.
     */
    public function gainPercent(): ?Decimal
    {
        return PortfolioValue::percent($this->gain(), $this->holding->costBase);
    }
}
