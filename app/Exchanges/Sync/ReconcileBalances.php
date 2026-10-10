<?php

namespace App\Exchanges\Sync;

use App\Exchanges\Contracts\ReportsBalances;
use App\Exchanges\ExchangeDriver;
use App\Exchanges\Market;
use App\Exchanges\Markets;
use App\Ledger\Holdings;
use App\Models\Asset;
use App\Models\Exchange;
use App\Notifications\BalanceMismatch;
use App\Support\Decimal;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The last step of a sync: compare what the exchange says the account holds
 * of each asset with what the ledger says, and tell the owner where they
 * differ by more than the tolerance (`nugget.sync.balance_tolerance`).
 *
 * It never changes the ledger. The owner has one BalanceMismatch
 * notification for an asset for as long as it is out: a sync that finds the
 * same difference leaves it alone, one that finds another updates it and
 * marks it unread, and one that finds the two agree deletes it.
 *
 * The assets compared are the ones that receive the exchange's trades
 * (`$exchange->tradedAssets()`): an asset that only shares a market for its
 * price, such as a cold wallet, is not on the exchange. A balance of a coin
 * with no asset is not looked at.
 */
class ReconcileBalances
{
    public function __construct(
        private readonly Holdings $holdings,
        private readonly Markets $markets,
    ) {}

    /**
     * @return null there is nothing to note: a driver that reports no balances has said so already
     */
    public function run(Exchange $exchange, ExchangeDriver $driver): ?string
    {
        if ($driver instanceof ReportsBalances) {
            $this->reconcile($exchange);
        }

        return null;
    }

    /**
     * Compare the exchange's stored balances with the ledger.
     */
    public function reconcile(Exchange $exchange): void
    {
        $owner = $exchange->user;
        $balances = $exchange->balances()->pluck('units', 'symbol');
        $bases = collect($this->markets->for($exchange))->mapWithKeys(fn (Market $market) => [$market->id => $market->base]);
        $tolerance = Decimal::of((string) config('nugget.sync.balance_tolerance'));

        $known = $owner->notifications()
            ->where('type', BalanceMismatch::class)
            ->where('data->exchange_id', $exchange->id)
            ->get()
            ->keyBy(fn (DatabaseNotification $notification) => $notification->data['asset_id']);

        foreach ($exchange->tradedAssets() as $asset) {
            // The exchange's own name for the coin, or the asset's symbol when its markets are not known.
            $onExchange = $balances->get($bases->get($asset->market_id, $asset->symbol)) ?? Decimal::zero();
            $holding = $this->holdings->forAsset($asset);
            $notification = $known->pull($asset->id);

            if (! $onExchange->sub($holding->units)->abs()->greaterThan($tolerance)) {
                $notification?->delete();

                continue;
            }

            $mismatch = new BalanceMismatch($exchange, $holding, $onExchange);

            if (! $notification) {
                $owner->notify($mismatch);
            } elseif ($notification->data != $mismatch->toArray($owner)) {
                $notification->forceFill(['data' => $mismatch->toArray($owner), 'read_at' => null])->save();
            }
        }

        // Left over: an asset that is no longer one of the exchange's.
        $known->each->delete();
    }
}
