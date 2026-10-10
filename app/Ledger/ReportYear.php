<?php

namespace App\Ledger;

use App\Support\FinancialYear;

/**
 * A financial year in which a user disposed of something.
 *
 * It is open when Nugget reports on it: the year named by the
 * `first_report_year` setting, or a later one. An earlier year is closed. Its
 * tax was lodged outside Nugget, so its disposals are history and no report
 * is offered for it.
 */
final readonly class ReportYear
{
    /**
     * @param  int  $disposals  the number of disposal lines in the year
     */
    public function __construct(
        public FinancialYear $year,
        public int $disposals,
        public bool $open,
    ) {}
}
