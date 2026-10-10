<?php

namespace App\Exchanges\Sync;

use App\Exchanges\Balance;
use App\Exchanges\Contracts\ReportsBalances;
use App\Exchanges\ExchangeDriver;
use App\Models\Exchange;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * The first step of a sync: read what the account holds, keep it as the
 * exchange's balances and set its AUD balance.
 *
 * It only records what the exchange says. It never touches the ledger.
 */
class SyncBalances
{
    /**
     * @return string|null a note when the step was skipped, null when it ran
     *
     * @throws \Throwable when the exchange cannot be read. Nothing is changed then.
     */
    public function run(Exchange $exchange, ExchangeDriver $driver): ?string
    {
        if (! $driver instanceof ReportsBalances) {
            return 'Balances were not read: '.$driver::label().' has no call for them.';
        }

        // Zero balances are not kept: an exchange lists every coin it trades.
        $held = collect($driver->balances())
            ->reject(fn (Balance $balance) => $balance->units->isZero())
            ->keyBy(fn (Balance $balance) => $balance->symbol);

        DB::transaction(function () use ($exchange, $held) {
            $exchange->balances()->delete();

            foreach ($held as $balance) {
                $exchange->balances()->create([
                    'symbol' => $balance->symbol,
                    'units' => $balance->units,
                    'available' => $balance->available,
                ]);
            }

            $exchange->forceFill(['aud_balance' => $held->get('AUD')?->units ?? Decimal::zero()])->save();
        });

        return null;
    }
}
