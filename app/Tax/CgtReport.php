<?php

namespace App\Tax;

use App\Enums\TaxSection;
use App\Models\Disposal;
use App\Support\FinancialYear;
use Illuminate\Support\Collection;

/**
 * A user's capital gains for one financial year: every disposal, by section,
 * and the summary worked from them.
 */
final readonly class CgtReport
{
    /**
     * @param  array<string, Collection<int, Disposal>>  $disposals  by TaxSection value, each in the order sold
     */
    public function __construct(
        public FinancialYear $year,
        public CgtSummary $summary,
        public array $disposals,
    ) {}

    /**
     * @return Collection<int, Disposal>
     */
    public function disposals(TaxSection $section): Collection
    {
        return $this->disposals[$section->value];
    }

    /**
     * How many disposals in the year used a parcel with an estimated cost.
     */
    public function estimated(): int
    {
        return array_sum(array_map(fn (CgtSection $section) => $section->estimated, $this->summary->sections));
    }
}
