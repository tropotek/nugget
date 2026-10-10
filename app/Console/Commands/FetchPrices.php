<?php

namespace App\Console\Commands;

use App\Enums\PricedBy;
use App\Models\Asset;
use App\Prices\PriceRecorder;
use App\Prices\PriceSources;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchPrices extends Command
{
    protected $signature = 'nugget:fetch-prices
                            {--asset= : Fetch the price of the asset with this id only, archived or not}';

    protected $description = 'Fetch the current price of every active asset that has a price source, and store it';

    public function handle(PriceSources $sources, PriceRecorder $recorder): int
    {
        $assets = $this->assets();

        if ($assets === null) {
            $this->components->error("There is no asset with the id {$this->option('asset')}.");

            return self::FAILURE;
        }

        // One time for the whole run, so its rows line up.
        $at = now()->startOfMinute();
        $fetched = 0;

        foreach ($assets as $asset) {
            try {
                $quote = $sources->quote($asset);

                if ($quote) {
                    $recorder->record($asset, $quote, $at);
                    $fetched++;
                }
            } catch (Throwable $e) {
                Log::warning("The price of asset {$asset->id} ({$asset->name}) could not be stored: {$e->getMessage()}");
            }
        }

        $failed = $assets->count() - $fetched;

        if ($failed === 0) {
            $this->components->info("Fetched {$fetched} of {$assets->count()} prices.");
        } else {
            $this->components->warn("Fetched {$fetched} of {$assets->count()} prices. {$failed} failed: see the log.");
        }

        return $assets->isNotEmpty() && $fetched === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The asset asked for, or every asset with a price source that is not
     * archived and belongs to an active user. Null when the asset asked for
     * does not exist.
     *
     * @return Collection<int, Asset>|null
     */
    private function assets(): ?Collection
    {
        if ($this->option('asset') !== null) {
            $asset = Asset::with('user', 'exchange')->find($this->option('asset'));

            return $asset ? new Collection([$asset]) : null;
        }

        return Asset::with('user', 'exchange')
            ->where('archived', false)
            ->whereRelation('user', 'active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Asset $asset) => $asset->pricedBy() !== PricedBy::Nothing)
            ->values();
    }
}
