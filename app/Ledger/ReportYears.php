<?php

namespace App\Ledger;

use App\Facades\Settings;
use App\Models\Disposal;
use App\Models\User;
use App\Support\FinancialYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The financial years in which a user has disposals. A disposal falls in the
 * year of its sale, by the user's calendar.
 *
 * Like Holdings, it does no authorising.
 */
class ReportYears
{
    /**
     * Each year with a disposal, the latest first.
     *
     * @return Collection<int, ReportYear>
     */
    public function forUser(User $user): Collection
    {
        $first = (int) Settings::get('first_report_year');

        return Disposal::query()
            ->join('transactions', 'transactions.id', '=', 'disposals.transaction_id')
            ->whereHas('transaction.asset', fn (Builder $asset) => $asset->where('user_id', $user->id))
            ->toBase()
            ->pluck('transactions.occurred_at')
            // The dates are stored in UTC, and the year turns at the user's midnight.
            ->countBy(fn (string $soldAt) => FinancialYear::containing(Carbon::parse($soldAt, 'UTC'), $user->timezone)->year)
            ->sortKeysDesc()
            ->map(fn (int $disposals, int $year) => new ReportYear(new FinancialYear($year), $disposals, $year >= $first))
            ->values();
    }

    /**
     * The years a report is offered for, the latest first.
     *
     * @return Collection<int, ReportYear>
     */
    public function open(User $user): Collection
    {
        return $this->forUser($user)->filter(fn (ReportYear $year) => $year->open)->values();
    }
}
