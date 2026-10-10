<?php

namespace App\Tax;

use App\Enums\AssetClass;
use App\Enums\TaxSection;
use App\Models\Disposal;
use App\Models\TaxYear;
use App\Models\User;
use App\Support\Decimal;
use App\Support\FinancialYear;
use Illuminate\Database\Eloquent\Builder;

/**
 * Works out a user's capital gains report for a financial year from the
 * disposals the parcel matcher wrote. The web page, the PDF, the CSV and the
 * API all read this, so their figures cannot differ.
 *
 * A disposal is in the year of its sale, by the user's calendar. Those of an
 * asset class the report does not take in are left out of the sections and of
 * the summary alike. It does no
 * authorising, and does not ask whether the year is open.
 */
class CgtCalculator
{
    /**
     * @param  Decimal|null  $carriedForward  losses brought in from earlier years. Left out, it is what the user saved for the year
     * @param  list<AssetClass>|null  $classes  the asset classes to take in. Left out, they are the ones the user chose for the year, or all
     */
    public function forYear(User $user, FinancialYear $year, ?Decimal $carriedForward = null, ?array $classes = null): CgtReport
    {
        $classes ??= TaxYear::classesIn($user, $year);

        $disposals = Disposal::query()
            ->select('disposals.*')
            ->join('transactions', 'transactions.id', '=', 'disposals.transaction_id')
            ->whereHas('transaction.asset', fn (Builder $asset) => $asset->where('user_id', $user->id)->whereIn('class', $classes))
            ->where('transactions.occurred_at', '>=', $year->start($user->timezone)->utc())
            ->where('transactions.occurred_at', '<', $year->end($user->timezone)->utc())
            ->orderBy('transactions.occurred_at')
            ->orderBy('disposals.transaction_id')
            ->orderBy('disposals.id')
            ->with(['transaction', 'parcel.asset'])
            ->get()
            ->groupBy(fn (Disposal $disposal) => TaxSection::of($disposal->parcel->asset->class)->value);

        $bySection = [];
        $sections = [];

        foreach (TaxSection::cases() as $section) {
            $bySection[$section->value] = $disposals->get($section->value, collect())->toBase();
            $sections[] = CgtSection::of($section, $bySection[$section->value]);
        }

        return new CgtReport($year, CgtSummary::of($sections, $carriedForward ?? TaxYear::lossesCarriedInto($user, $year)), $bySection, $classes);
    }
}
