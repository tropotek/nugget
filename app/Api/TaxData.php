<?php

namespace App\Api;

use App\Enums\AssetClass;
use App\Facades\Settings;
use App\Ledger\ReportYear;
use App\Ledger\ReportYears;
use App\Models\Disposal;
use App\Models\User;
use App\Support\FinancialYear;
use App\Tax\CgtCalculator;
use App\Tax\CgtSection;

/**
 * The capital gains report: the years it can be had for, and one year's.
 */
class TaxData
{
    public const NOTE = 'A working paper, not tax advice. It covers capital gains only: staking rewards and airdrops are not totalled as income, and metals are not treated as collectables or personal use assets.';

    public function __construct(
        private readonly ReportYears $years,
        private readonly CgtCalculator $calculator,
    ) {}

    /**
     * The financial years in which the user sold something, the latest
     * first. A year is named by the year it ends in: 2025 is 2024–25. Only
     * an open year has a report.
     *
     * @return array<string, mixed>
     */
    public function years(User $user): array
    {
        return [
            'first_report_year' => (int) Settings::get('first_report_year'),
            'years' => $this->years->forUser($user)->map(fn (ReportYear $year) => [
                ...$this->year($year->year, $user),
                'open' => $year->open,
                'disposals' => $year->disposals,
            ])->all(),
        ];
    }

    /**
     * Whether a year has a report: the first report year or later, and not
     * beyond next year.
     */
    public function isOpen(int $year): bool
    {
        return $year >= (int) Settings::get('first_report_year') && $year <= Settings::currentYear() + 1;
    }

    /**
     * One year's report as the user has it set up: the asset classes they
     * chose for it and the losses they carried into it. It is the report the
     * tax report page, the PDF and the CSV show.
     *
     * @return array<string, mixed>
     */
    public function report(User $user, int $year): array
    {
        $report = $this->calculator->forYear($user, new FinancialYear($year));
        $summary = $report->summary;
        $estimated = $report->estimated();
        $classes = fn (array $list) => array_map(fn (AssetClass $class) => $class->value, $list);

        return [
            ...$this->year($report->year, $user),
            'currency' => 'AUD',
            'note' => self::NOTE,
            'asset_classes' => $classes($report->classes),
            'asset_classes_left_out' => $classes($report->leftOut()),
            'estimated_disposals' => $estimated,
            'warning' => $estimated > 0
                ? "{$estimated} of the disposals used a parcel with an estimated cost, so these figures are not final."
                : null,
            'summary' => [
                'short_held_gains' => Format::aud($summary->shortGains),
                'long_held_gains' => Format::aud($summary->longGains),
                'total_gains' => Format::aud($summary->totalGains()),
                'losses' => Format::aud($summary->losses),
                'losses_carried_forward_in' => Format::aud($summary->carriedForward),
                'losses_applied' => Format::aud($summary->lossesApplied),
                'short_held_gains_after_losses' => Format::aud($summary->shortAfterLosses),
                'long_held_gains_after_losses' => Format::aud($summary->longAfterLosses),
                'discount' => Format::aud($summary->discount),
                'net_capital_gain' => Format::aud($summary->netGain),
                'losses_carried_forward_out' => Format::aud($summary->lossCarriedForward),
            ],
            'sections' => array_map(fn (CgtSection $section) => [
                'section' => $section->section->value,
                'label' => $section->section->label(),
                'included' => $report->takesIn($section->section),
                'short_held_gains' => Format::aud($section->shortGains),
                'long_held_gains' => Format::aud($section->longGains),
                'losses' => Format::aud($section->losses),
                'estimated_disposals' => $section->estimated,
                'disposals' => $report->disposals($section->section)->map(fn (Disposal $disposal) => [
                    'asset' => Format::asset($disposal->parcel->asset),
                    'acquired_at' => Format::date($disposal->parcel->acquired_at, $user),
                    'sold_at' => Format::date($disposal->transaction->occurred_at, $user),
                    'units' => Format::number($disposal->units),
                    'cost_base' => Format::aud($disposal->cost_base),
                    'proceeds' => Format::aud($disposal->proceeds),
                    'gain' => Format::aud($disposal->gain),
                    'held_over_12_months' => $disposal->long_held,
                    'discount_applies' => $disposal->long_held && $disposal->gain->isPositive(),
                    'estimated' => $disposal->parcel->estimated,
                    'sale_transaction_id' => $disposal->transaction_id,
                ])->all(),
            ], $summary->sections),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function year(FinancialYear $year, User $user): array
    {
        return [
            'year' => $year->year,
            'label' => $year->label(),
            'starts_at' => Format::date($year->start($user->timezone), $user),
            // The first moment of the next year, which is not in this one.
            'ends_before' => Format::date($year->end($user->timezone), $user),
        ];
    }
}
