<?php

namespace Tests\Unit\Tax;

use App\Enums\TaxSection;
use App\Support\Decimal;
use App\Tax\CgtSection;
use App\Tax\CgtSummary;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Worked examples of the net capital gain for a year.
 *
 * The method is the ATO's: add up the gains, take the losses off (this
 * year's and any carried forward from earlier years), halve what is left of
 * the gains on assets held longer than 12 months, and what remains is the
 * net capital gain. The losses come off the short-held gains first, because
 * those get no discount: that order gives the lowest net figure.
 */
class CgtSummaryTest extends TestCase
{
    private function section(TaxSection $section, string $short, string $long, string $losses): CgtSection
    {
        return new CgtSection($section, Decimal::of($short), Decimal::of($long), Decimal::of($losses), disposals: 1, estimated: 0);
    }

    /**
     * A year with these totals, all in the crypto section.
     */
    private function year(string $short, string $long, string $losses, string $carriedForward = '0'): CgtSummary
    {
        return CgtSummary::of(
            [$this->section(TaxSection::Crypto, $short, $long, $losses), $this->section(TaxSection::Other, '0', '0', '0')],
            Decimal::of($carriedForward),
        );
    }

    /**
     * The working as [short after losses, long after losses, discount, net gain, loss to carry forward].
     *
     * @return list<string>
     */
    private function working(CgtSummary $summary): array
    {
        return array_map(fn (Decimal $amount) => $amount->toFixed(2), [
            $summary->shortAfterLosses,
            $summary->longAfterLosses,
            $summary->discount,
            $summary->netGain,
            $summary->lossCarriedForward,
        ]);
    }

    public function test_a_year_with_no_disposals_has_no_gain_and_no_loss(): void
    {
        $summary = CgtSummary::of([], Decimal::zero());

        $this->assertSame(['0.00', '0.00', '0.00', '0.00', '0.00'], $this->working($summary));
        $this->assertTrue($summary->totalGains()->isZero());
    }

    public function test_a_short_held_gain_is_taxed_in_full(): void
    {
        $this->assertSame(['1000.00', '0.00', '0.00', '1000.00', '0.00'], $this->working($this->year('1000', '0', '0')));
    }

    public function test_a_long_held_gain_is_halved(): void
    {
        $this->assertSame(['0.00', '1000.00', '500.00', '500.00', '0.00'], $this->working($this->year('0', '1000', '0')));
    }

    public function test_short_and_long_held_gains_with_no_losses(): void
    {
        // $1,000 in full and half of $4,000.
        $this->assertSame(['1000.00', '4000.00', '2000.00', '3000.00', '0.00'], $this->working($this->year('1000', '4000', '0')));
    }

    public function test_losses_come_off_the_short_held_gains_first(): void
    {
        // $1,500 of losses: $1,000 clears the short-held gains and $500 comes off the long-held,
        // leaving $3,500 to halve. Taken off the long-held first, the net would be $2,250.
        $summary = $this->year('1000', '4000', '1500');

        $this->assertSame(['0.00', '3500.00', '1750.00', '1750.00', '0.00'], $this->working($summary));
        $this->assertSame('1500.00', $summary->lossesApplied->toFixed(2));
    }

    public function test_losses_smaller_than_the_short_held_gains_leave_the_long_held_whole(): void
    {
        $this->assertSame(['600.00', '4000.00', '2000.00', '2600.00', '0.00'], $this->working($this->year('1000', '4000', '400')));
    }

    public function test_losses_carried_forward_from_earlier_years_are_applied_as_well(): void
    {
        // $1,500 this year and $2,000 from before: $3,500 against $5,000 of gains.
        $summary = $this->year('1000', '4000', '1500', carriedForward: '2000');

        $this->assertSame(['0.00', '1500.00', '750.00', '750.00', '0.00'], $this->working($summary));
        $this->assertSame('3500.00', $summary->totalLosses()->toFixed(2));
        $this->assertSame('3500.00', $summary->lossesApplied->toFixed(2));
    }

    public function test_a_net_loss_year_has_no_net_gain_and_carries_the_rest_forward(): void
    {
        // $2,000 of losses against $800 of gains.
        $summary = $this->year('500', '300', '2000');

        $this->assertSame(['0.00', '0.00', '0.00', '0.00', '1200.00'], $this->working($summary));
        $this->assertSame('800.00', $summary->lossesApplied->toFixed(2));
    }

    public function test_a_year_of_losses_alone_carries_them_all_forward_with_those_from_before(): void
    {
        $this->assertSame(['0.00', '0.00', '0.00', '0.00', '2500.00'], $this->working($this->year('0', '0', '2000', carriedForward: '500')));
    }

    public function test_losses_equal_to_the_gains_leave_nothing_either_way(): void
    {
        $this->assertSame(['0.00', '0.00', '0.00', '0.00', '0.00'], $this->working($this->year('500', '300', '800')));
    }

    public function test_the_discount_is_not_given_on_a_gain_the_losses_used_up(): void
    {
        // The long-held gain is gone, so there is nothing to halve: the losses are not halved with it.
        $this->assertSame(['0.00', '0.00', '0.00', '0.00', '100.00'], $this->working($this->year('0', '900', '1000')));
    }

    public function test_the_discount_is_rounded_to_the_cent(): void
    {
        // Half of $100.01 is $50.005.
        $this->assertSame(['0.00', '100.01', '50.01', '50.00', '0.00'], $this->working($this->year('0', '100.01', '0')));
    }

    public function test_the_sections_are_added_up_and_a_loss_in_one_comes_off_a_gain_in_the_other(): void
    {
        $summary = CgtSummary::of([
            $this->section(TaxSection::Crypto, '1000', '2000', '0'),
            $this->section(TaxSection::Other, '0', '500', '1400'),
        ], Decimal::zero());

        $this->assertSame('1000.00', $summary->shortGains->toFixed(2));
        $this->assertSame('2500.00', $summary->longGains->toFixed(2));
        $this->assertSame('1400.00', $summary->losses->toFixed(2));
        $this->assertSame('3500.00', $summary->totalGains()->toFixed(2));
        // $1,000 off the short-held and $400 off the long-held: half of $2,100.
        $this->assertSame(['0.00', '2100.00', '1050.00', '1050.00', '0.00'], $this->working($summary));
        $this->assertSame([TaxSection::Crypto, TaxSection::Other], array_map(fn (CgtSection $section) => $section->section, $summary->sections));
    }

    public function test_a_found_parcel_sold_is_all_gain(): void
    {
        // Prospected gold at no cost, sold for $445.50 within the year.
        $this->assertSame(['445.50', '0.00', '0.00', '445.50', '0.00'], $this->working($this->year('445.50', '0', '0')));
    }

    public function test_losses_carried_forward_cannot_be_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CgtSummary::of([], Decimal::of('-1'));
    }
}
