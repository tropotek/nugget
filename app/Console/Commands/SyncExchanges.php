<?php

namespace App\Console\Commands;

use App\Exchanges\Sync\ExchangeSync;
use App\Exchanges\Sync\SyncOutcome;
use App\Models\Exchange;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class SyncExchanges extends Command
{
    protected $signature = 'nugget:sync-exchanges
                            {--exchange= : Sync the exchange with this id only, active or not}
                            {--all-trades : Read the trades again from the first. One already in the ledger is not stored twice}';

    protected $description = 'Sync every active exchange: store its balances and new trades, and compare them with the ledger';

    public function handle(ExchangeSync $sync): int
    {
        $exchanges = $this->exchanges();

        if ($exchanges === null) {
            $this->components->error("There is no exchange with the id {$this->option('exchange')}.");

            return self::FAILURE;
        }

        if ($this->option('all-trades')) {
            $exchanges->each(fn (Exchange $exchange) => $exchange->forceFill(['trade_cursor' => null])->save());
        }

        // A sync never throws: a failure is on the exchange and in the log, and the rest go on.
        $outcomes = $exchanges->map(fn (Exchange $exchange) => $sync->run($exchange));
        $synced = $outcomes->filter(fn (SyncOutcome $outcome) => $outcome === SyncOutcome::Synced)->count();
        $failed = $outcomes->filter(fn (SyncOutcome $outcome) => $outcome === SyncOutcome::Failed)->count();
        $busy = $exchanges->count() - $synced - $failed;

        $summary = "Synced {$synced} of {$exchanges->count()} exchanges."
            .($failed > 0 ? " {$failed} failed: see the exchanges page." : '')
            .($busy > 0 ? " {$busy} already being synced." : '');

        $failed > 0 ? $this->components->warn($summary) : $this->components->info($summary);

        // An exchange that is busy is being seen to: only a run where every one failed is a failure.
        return $failed > 0 && $synced === 0 ? self::FAILURE : self::SUCCESS;
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
