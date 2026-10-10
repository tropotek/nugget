<?php

namespace App\Console\Commands;

use App\Exchanges\Sync\ExchangeSync;
use App\Models\Exchange;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SyncExchanges extends Command
{
    protected $signature = 'nugget:sync-exchanges
                            {--exchange= : Sync the exchange with this id only, active or not}';

    protected $description = 'Sync every active exchange: read its balances and store them';

    public function handle(ExchangeSync $sync): int
    {
        $exchanges = $this->exchanges();

        if ($exchanges === null) {
            $this->components->error("There is no exchange with the id {$this->option('exchange')}.");

            return self::FAILURE;
        }

        // A sync never throws: a failure is on the exchange and in the log, and the rest go on.
        $synced = $exchanges->filter(fn (Exchange $exchange) => $sync->run($exchange))->count();
        $failed = $exchanges->count() - $synced;

        if ($failed === 0) {
            $this->components->info("Synced {$synced} of {$exchanges->count()} exchanges.");
        } else {
            $this->components->warn("Synced {$synced} of {$exchanges->count()} exchanges. {$failed} failed: see the exchanges page.");
        }

        return $exchanges->isNotEmpty() && $synced === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The exchange asked for, or every active exchange of an active user.
     * Null when the exchange asked for does not exist.
     *
     * @return Collection<int, Exchange>|null
     */
    private function exchanges(): ?Collection
    {
        if ($this->option('exchange') !== null) {
            $exchange = Exchange::find($this->option('exchange'));

            return $exchange ? new Collection([$exchange]) : null;
        }

        return Exchange::query()
            ->where('active', true)
            ->whereRelation('user', 'active', true)
            ->orderBy('id')
            ->get();
    }
}
