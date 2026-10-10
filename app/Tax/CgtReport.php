<?php

namespace App\Tax;

use App\Enums\AssetClass;
use App\Enums\TaxSection;
use App\Models\Disposal;
use App\Support\FinancialYear;
use Illuminate\Support\Collection;

/**
 * A user's capital gains for one financial year: the disposals of the asset
 * classes it takes in, by section, and the summary worked from them.
 */
final readonly class CgtReport
{
    /**
     * @param  array<string, Collection<int, Disposal>>  $disposals  by TaxSection value, each in the order sold
     * @param  list<AssetClass>  $classes  the asset classes the report takes in
     */
    public function __construct(
        public FinancialYear $year,
        public CgtSummary $summary,
        public array $disposals,
        public array $classes,
    ) {}

    /**
     * The asset classes left out of the report. Their disposals are in
     * neither the sections nor the summary.
     *
     * @return list<AssetClass>
     */
    public function leftOut(): array
    {
        return array_values(array_filter(AssetClass::cases(), fn (AssetClass $class) => ! in_array($class, $this->classes, true)));
    }

    /**
     * Whether the report takes in any class that belongs to a section. A
     * section with none is left out rather than empty.
     */
    public function takesIn(TaxSection $section): bool
    {
        return array_any($this->classes, fn (AssetClass $class) => TaxSection::of($class) === $section);
    }

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
