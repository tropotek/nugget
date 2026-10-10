<?php

namespace App\Console\Commands;

use App\Enums\PriceResolution;
use App\Models\Price;
use Illuminate\Console\Command;

class PrunePrices extends Command
{
    protected $signature = 'nugget:prune-prices';

    protected $description = 'Delete the intraday prices older than the hours kept (48). Day prices are kept for good';

    public function handle(): int
    {
        $hours = (int) config('nugget.prices.intraday_hours');

        // Intraday rows only, whatever the cut-off: a day row is never deleted.
        $deleted = Price::query()
            ->where('resolution', PriceResolution::Intraday)
            ->where('at', '<', now()->subHours($hours))
            ->delete();

        $this->components->info("Deleted {$deleted} intraday prices older than {$hours} hours.");

        return self::SUCCESS;
    }
}
