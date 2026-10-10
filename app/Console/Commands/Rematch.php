<?php

namespace App\Console\Commands;

use App\Enums\TransactionType;
use App\Facades\Settings;
use App\Ledger\InsufficientUnits;
use App\Ledger\InvalidParcelPick;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Transaction;
use App\Models\User;
use App\Support\FinancialYear;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Rematch extends Command
{
    protected $signature = 'nugget:rematch
                            {--asset= : Rebuild the parcels of the asset with this id}
                            {--year= : Match the sales of a financial year again by the current setting. Name the year it ends in: 2025 for 2024–25}';

    protected $description = 'Rebuild parcels and disposals from the transactions: for every asset, for one asset, or one financial year by the current matching method';

    public function handle(ParcelMatcher $matcher): int
    {
        if ($this->option('asset') !== null && $this->option('year') !== null) {
            $this->components->error('Give an asset or a year, not both.');

            return self::FAILURE;
        }

        if ($this->option('year') !== null) {
            return $this->rematchYear($matcher, new FinancialYear((int) $this->option('year')));
        }

        if ($this->option('asset') === null) {
            return $this->rematch($matcher, Asset::all());
        }

        $asset = Asset::find($this->option('asset'));

        if (! $asset) {
            $this->components->error("There is no asset with the id {$this->option('asset')}.");

            return self::FAILURE;
        }

        return $this->rematch($matcher, collect([$asset]));
    }

    /**
     * Forget the method of the year's sales, so the rematch gives them the
     * current one. Sales matched by hand keep their parcels.
     */
    private function rematchYear(ParcelMatcher $matcher, FinancialYear $year): int
    {
        $firstYear = new FinancialYear(Settings::get('first_report_year'));

        if ($year->year < $firstYear->year) {
            $this->components->error("{$year->label()} is closed: the first financial year reported is {$firstYear->label()}.");

            return self::FAILURE;
        }

        $this->components->info("Matching the sales of {$year->label()} again by the current method.");

        try {
            DB::transaction(function () use ($matcher, $year) {
                $assets = collect();

                foreach (User::has('assets')->get() as $user) {
                    $sales = Transaction::query()
                        ->whereIn('asset_id', $user->assets()->select('id'))
                        ->where('type', TransactionType::Sell)
                        ->where('occurred_at', '>=', $year->start($user->timezone)->utc())
                        ->where('occurred_at', '<', $year->end($user->timezone)->utc())
                        ->doesntHave('picks');

                    $assets = $assets->merge(Asset::findMany((clone $sales)->distinct()->pluck('asset_id')));
                    $sales->update(['matching' => null]);
                }

                foreach ($this->pools($assets) as $asset) {
                    $matcher->rematch($asset);
                    $this->components->twoColumnDetail("{$asset->name} ({$asset->symbol})", 'rematched');
                }
            });
        } catch (InsufficientUnits|InvalidParcelPick $e) {
            $this->components->error($e->getMessage());
            $this->components->error("Nothing was changed for {$year->label()}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Rematch each pool of assets once. A pool that cannot be matched is
     * reported and left as it was.
     *
     * @param  Collection<int, Asset>  $assets
     */
    private function rematch(ParcelMatcher $matcher, Collection $assets): int
    {
        $result = self::SUCCESS;

        foreach ($this->pools($assets) as $asset) {
            try {
                $matcher->rematch($asset);
                $this->components->twoColumnDetail("{$asset->name} ({$asset->symbol})", 'rematched');
            } catch (InsufficientUnits|InvalidParcelPick $e) {
                $this->components->error($e->getMessage());
                $result = self::FAILURE;
            }
        }

        return $result;
    }

    /**
     * One asset from each pool that is rematched together.
     *
     * @param  Collection<int, Asset>  $assets
     * @return Collection<int, Asset>
     */
    private function pools(Collection $assets): Collection
    {
        return $assets->unique(fn (Asset $asset) => "{$asset->user_id}|{$asset->symbol}|{$asset->unit}");
    }
}
