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
 * The financial years of a user's tax reports. A disposal falls in the year of
 * its sale, by the user's calendar.
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
     * The years a report is offered for, the latest first: every year from
     * the first report year to the one in progress, whether or not anything
     * was sold in it. A year with nothing sold has a report of zeros.
     *
     * @return Collection<int, ReportYear>
     */
    public function open(User $user): Collection
    {
        $first = (int) Settings::get('first_report_year');
        $current = FinancialYear::containing(now(), $user->timezone)->year;
        $sold = $this->forUser($user)->keyBy(fn (ReportYear $year) => $year->year->year);

        // A sale dated ahead of today is still offered.
        $last = max($current, (int) $sold->keys()->max());

        return collect($last >= $first ? range($last, $first) : [])
            ->map(fn (int $year) => $sold->get($year) ?? new ReportYear(new FinancialYear($year), 0, true))
            ->values();
    }

    /**
     * Every year worth listing, the latest first: the open ones, and the
     * closed ones in which something was sold.
     *
     * @return Collection<int, ReportYear>
     */
    public function all(User $user): Collection
    {
        return $this->open($user)
            ->concat($this->forUser($user)->reject(fn (ReportYear $year) => $year->open))
            ->sortByDesc(fn (ReportYear $year) => $year->year->year)
            ->values();
    }
}
