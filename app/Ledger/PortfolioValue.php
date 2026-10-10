<?php

namespace App\Ledger;

use App\Support\Decimal;

/**
 * The totals of a user's valuations, over the assets that count toward the
 * total (`in_total`).
 *
 * A holding with no price has no value, so it is left out of the cost base
 * too, which keeps the gain a like-for-like figure. `unpriced` says how many
 * such holdings there are.
 */
final readonly class PortfolioValue
{
    private function __construct(
        private Decimal $value,
        public Decimal $costBase,
        public int $unpriced,
    ) {}

    /**
     * @param  iterable<Valuation>  $valuations
     */
    public static function of(iterable $valuations): self
    {
        $counted = collect($valuations)->filter(fn (Valuation $valuation) => $valuation->holding->asset->in_total);
        $priced = $counted->filter(fn (Valuation $valuation) => $valuation->isPriced());

        return new self(
            Decimal::sum($priced->map(fn (Valuation $valuation) => $valuation->value())),
            Decimal::sum($priced->map(fn (Valuation $valuation) => $valuation->holding->costBase)),
            $counted->reject(fn (Valuation $valuation) => $valuation->isPriced() || $valuation->holding->isEmpty())->count(),
        );
    }

    /**
     * A gain as a percentage of a cost base, to two places. Null when either is missing or the cost is zero.
     */
    public static function percent(?Decimal $gain, ?Decimal $costBase): ?Decimal
    {
        return $gain === null || $costBase === null || $costBase->isZero() ? null : $gain->mul(100)->div($costBase, 2);
    }

    public function value(): Decimal
    {
        return $this->value;
    }

    public function gain(): Decimal
    {
        return $this->value->sub($this->costBase);
    }

    public function gainPercent(): ?Decimal
    {
        return self::percent($this->gain(), $this->costBase);
    }
}
