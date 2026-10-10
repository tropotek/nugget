<?php

namespace App\Exchanges\Sync;

use App\Exchanges\ExchangeDrivers;
use App\Exchanges\Failure;
use App\Models\Exchange;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings one exchange up to date, a step at a time: its balances, its trades,
 * then a comparison of the two with the ledger.
 *
 * It never throws. A sync that works sets the exchange's `last_synced_at`,
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

    /**
     * @return bool whether the sync worked
     */
    public function run(Exchange $exchange): bool
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

            return false;
        }

        $exchange->forceFill([
            'last_synced_at' => now(),
            'last_error' => null,
            'last_sync_note' => $notes ? implode(' ', $notes) : null,
        ])->save();

        return true;
    }
}
