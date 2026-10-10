<?php

namespace App\Exchanges\Sync;

use App\Exchanges\ExchangeDrivers;
use App\Exchanges\Failure;
use App\Models\Exchange;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings one exchange up to date, a step at a time: its balances, its trades,
 * then a comparison of the two with the ledger.
 *
 * It never throws, and syncs an exchange once at a time. A sync that works sets the exchange's `last_synced_at`,
 * clears its `last_error` and sets `last_sync_note` to the steps its driver
 * could not do, which is a normal outcome. A step that fails stops the sync:
 * the reason goes in `last_error` and a warning in the log, and the time of
 * the last good sync is left as it was.
 */
class ExchangeSync
{
    public function __construct(
        private readonly ExchangeDrivers $drivers,
        private readonly SyncBalances $balances,
        private readonly SyncTrades $trades,
        private readonly ReconcileBalances $reconcile,
    ) {}

    /** A lock left by a sync that died is dropped after this many seconds. */
    private const LOCK_SECONDS = 600;

    /**
     * One sync of an exchange at a time: the schedule and the "Sync now"
     * button may ask at the same moment, and the second is told it is busy.
     */
    public function run(Exchange $exchange): SyncOutcome
    {
        $lock = Cache::lock("exchange-sync:{$exchange->id}", self::LOCK_SECONDS);

        if (! $lock->get()) {
            return SyncOutcome::Busy;
        }

        try {
            return $this->sync($exchange);
        } finally {
            $lock->release();
        }
    }

    private function sync(Exchange $exchange): SyncOutcome
    {
        try {
            $driver = $this->drivers->for($exchange);

            $notes = array_filter([
                $this->balances->run($exchange, $driver),
                $this->trades->run($exchange, $driver),
                $this->reconcile->run($exchange, $driver),
            ]);
        } catch (Throwable $e) {
            Log::warning("The sync of exchange {$exchange->id} ({$exchange->label}) failed: {$e->getMessage()}");

            $exchange->forceFill(['last_error' => Failure::reason($e)])->save();

            return SyncOutcome::Failed;
        }

        $exchange->forceFill([
            'last_synced_at' => now(),
            'last_error' => null,
            'last_sync_note' => $notes ? implode(' ', $notes) : null,
        ])->save();

        return SyncOutcome::Synced;
    }
}
