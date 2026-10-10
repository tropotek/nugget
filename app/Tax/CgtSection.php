<?php

namespace App\Tax;

use App\Enums\TaxSection;
use App\Models\Disposal;
use App\Support\Decimal;

/**
 * The totals of one section of a year's capital gains report.
 *
 * A disposal is one parcel's part of one sale, and each is a gain or a loss
 * of its own. `losses` is their size, so it is not negative. The 12-month
 * discount belongs to gains only, so a loss is not split by how long its
 * parcel was held.
 */
final readonly class CgtSection
{
    /**
     * @param  Decimal  $shortGains  gains on parcels held 12 months or less
     * @param  Decimal  $longGains  gains on parcels held longer than 12 months, before the discount
     * @param  int  $disposals  the number of disposal lines
     * @param  int  $estimated  how many of them used a parcel with an estimated cost
     */
    public function __construct(
        public TaxSection $section,
        public Decimal $shortGains,
        public Decimal $longGains,
        public Decimal $losses,
        public int $disposals,
        public int $estimated,
    ) {}

    /**
     * @param  iterable<Disposal>  $disposals  with each one's parcel loaded
     */
    public static function of(TaxSection $section, iterable $disposals): self
    {
        $short = $long = $losses = Decimal::zero();
        $count = $estimated = 0;

        foreach ($disposals as $disposal) {
            $count++;
            $estimated += $disposal->parcel->estimated ? 1 : 0;

            if ($disposal->gain->isNegative()) {
                $losses = $losses->add($disposal->gain->abs());
            } elseif ($disposal->long_held) {
                $long = $long->add($disposal->gain);
            } else {
                $short = $short->add($disposal->gain);
            }
        }

        return new self($section, $short, $long, $losses, $count, $estimated);
    }
}
