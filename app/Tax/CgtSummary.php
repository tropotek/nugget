<?php

namespace App\Tax;

use App\Support\Decimal;
use InvalidArgumentException;

/**
 * The net capital gain of a year, worked from the totals of its sections.
 *
 * The losses, this year's and any carried forward from earlier years, come
 * off the gains whatever section either is in: first off the short-held
 * gains, which get no discount, then off the long-held. What is left of the
 * long-held gains is halved. Losses bigger than the gains are carried
 * forward to the next year. Applying the losses in that order gives the
 * lowest net gain the rules allow.
 *
 * It is a working paper, not tax advice: it knows nothing of collectables,
 * personal use assets or any other discount rate.
 */
final readonly class CgtSummary
{
    /** The share of a long-held gain that is taken off, for an individual. */
    public const DISCOUNT = '0.5';

    /**
     * @param  list<CgtSection>  $sections
     * @param  Decimal  $shortGains  gains on parcels held 12 months or less
     * @param  Decimal  $longGains  gains on parcels held longer than 12 months, before the discount
     * @param  Decimal  $losses  this year's losses
     * @param  Decimal  $carriedForward  losses brought in from earlier years
     * @param  Decimal  $lossesApplied  how much of the losses the gains used up
     * @param  Decimal  $shortAfterLosses  what is left of the short-held gains
     * @param  Decimal  $longAfterLosses  what is left of the long-held gains, before the discount
     * @param  Decimal  $discount  the half of that which is taken off
     * @param  Decimal  $netGain  the net capital gain: never below zero
     * @param  Decimal  $lossCarriedForward  the losses left over for next year
     */
    private function __construct(
        public array $sections,
        public Decimal $shortGains,
        public Decimal $longGains,
        public Decimal $losses,
        public Decimal $carriedForward,
        public Decimal $lossesApplied,
        public Decimal $shortAfterLosses,
        public Decimal $longAfterLosses,
        public Decimal $discount,
        public Decimal $netGain,
        public Decimal $lossCarriedForward,
    ) {}

    /**
     * @param  iterable<CgtSection>  $sections
     * @param  Decimal  $carriedForward  losses brought in from earlier years: zero or more
     */
    public static function of(iterable $sections, Decimal $carriedForward): self
    {
        if ($carriedForward->isNegative()) {
            throw new InvalidArgumentException('Losses carried forward are an amount of zero or more.');
        }

        $sections = array_values([...$sections]);
        $short = Decimal::sum(array_map(fn (CgtSection $section) => $section->shortGains, $sections));
        $long = Decimal::sum(array_map(fn (CgtSection $section) => $section->longGains, $sections));
        $losses = Decimal::sum(array_map(fn (CgtSection $section) => $section->losses, $sections));

        $available = $losses->add($carriedForward);

        $offShort = self::smaller($available, $short);
        $offLong = self::smaller($available->sub($offShort), $long);

        $shortLeft = $short->sub($offShort);
        $longLeft = $long->sub($offLong);
        $discount = $longLeft->mul(self::DISCOUNT)->round(Decimal::AUD_SCALE);

        return new self(
            $sections,
            $short,
            $long,
            $losses,
            $carriedForward,
            $offShort->add($offLong),
            $shortLeft,
            $longLeft,
            $discount,
            $shortLeft->add($longLeft)->sub($discount),
            $available->sub($offShort)->sub($offLong),
        );
    }

    /**
     * Every gain of the year, before the losses and the discount.
     */
    public function totalGains(): Decimal
    {
        return $this->shortGains->add($this->longGains);
    }

    /**
     * This year's losses and those carried forward from earlier years.
     */
    public function totalLosses(): Decimal
    {
        return $this->losses->add($this->carriedForward);
    }

    private static function smaller(Decimal $a, Decimal $b): Decimal
    {
        return $a->lessThan($b) ? $a : $b;
    }
}
