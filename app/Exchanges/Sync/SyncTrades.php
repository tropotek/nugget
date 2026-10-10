<?php

namespace App\Exchanges\Sync;

use App\Enums\TransactionSource;
use App\Exchanges\Contracts\ReportsTrades;
use App\Exchanges\ExchangeDriver;
use App\Exchanges\Trade;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Transaction;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * The second step of a sync: read the trades since the last one seen and
 * store each as a buy or a sell of the asset on its market.
 *
 * A trade is keyed by the exchange's own id, so one already in the ledger is
 * not stored twice. Each page of trades is saved and its assets rematched in
 * one database transaction, and the exchange's `trade_cursor` moves on with
 * it: a page the ledger cannot support (a sale of more than is held) is
 * refused whole, and the next sync starts from the same place.
 *
 * A trade is passed over, and counted in the note, when no asset has its
 * market, and when its market is not priced in AUD: a coin sold for another
 * coin is two entries, which this does not make.
 */
class SyncTrades
{
    /** The most pages read in one sync. The next sync carries on from there. */
    public const MAX_PAGES = 25;

    public function __construct(private readonly ParcelMatcher $matcher) {}

    /**
     * @return string|null a note when the step was skipped or trades were passed over
     *
     * @throws \Throwable when the exchange cannot be read or the ledger cannot take a trade
     */
    public function run(Exchange $exchange, ExchangeDriver $driver): ?string
    {
        if (! $driver instanceof ReportsTrades) {
            return 'Trades were not read: '.$driver::label().' has no call for them.';
        }

        $assets = $exchange->tradedAssets();
        $passedOver = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $trades = $driver->tradesAfter($exchange->trade_cursor);

            if ($trades === []) {
                return $this->note($passedOver);
            }

            $passedOver = [...$passedOver, ...$this->store($exchange, $assets, $trades)];
        }

        return trim($this->note($passedOver).' More trades remain: the next sync carries on.');
    }

    /**
     * @param  Collection<string, Asset>  $assets  by market id
     * @param  non-empty-list<Trade>  $trades  oldest first
     * @return list<string> the market of each trade passed over
     */
    private function store(Exchange $exchange, Collection $assets, array $trades): array
    {
        return DB::transaction(function () use ($exchange, $assets, $trades) {
            $passedOver = [];
            $changed = [];

            foreach ($trades as $trade) {
                $asset = $trade->quote === 'AUD' ? $assets->get($trade->market) : null;

                if (! $asset) {
                    $passedOver[] = $trade->market;

                    continue;
                }

                if ($this->add($asset, $trade)) {
                    $changed[$asset->id] = $asset;
                }
            }

            foreach ($changed as $asset) {
                $this->matcher->rematch($asset);
            }

            $exchange->forceFill(['trade_cursor' => end($trades)->id])->save();

            return $passedOver;
        });
    }

    /**
     * @return bool false when the trade is already in the ledger
     */
    private function add(Asset $asset, Trade $trade): bool
    {
        $key = ['source' => TransactionSource::Exchange, 'external_id' => $trade->id];

        if ($asset->transactions()->where($key)->exists()) {
            return false;
        }

        $units = $trade->units->round(Decimal::UNITS_SCALE);
        $price = $trade->price->round(Decimal::PRICE_SCALE);
        $fee = $this->feeInAud($asset, $trade);

        $asset->transactions()->create([
            ...$key,
            'type' => $trade->type,
            'occurred_at' => $trade->at,
            'units' => $units,
            'unit_price' => $price,
            'fee' => $fee,
            'total' => Transaction::totalOf($trade->type, $units, $price, $fee),
        ]);

        return true;
    }

    /**
     * A fee charged in the coin itself is worth what the trade paid for it.
     */
    private function feeInAud(Asset $asset, Trade $trade): Decimal
    {
        $fee = match (true) {
            $trade->feeSymbol === 'AUD' => $trade->fee,
            $trade->feeSymbol === $asset->symbol => $trade->fee->mul($trade->price),
            default => throw new UnexpectedValueException(
                "Trade {$trade->id} on {$trade->market} has a fee in {$trade->feeSymbol}, which is neither AUD nor {$asset->symbol}.",
            ),
        };

        return $fee->round(Decimal::AUD_SCALE);
    }

    /**
     * @param  list<string>  $passedOver  the market of each trade passed over
     */
    private function note(array $passedOver): ?string
    {
        if ($passedOver === []) {
            return null;
        }

        $markets = array_keys(array_count_values($passedOver));
        sort($markets);

        $count = count($passedOver);

        return ($count === 1 ? '1 trade was' : "{$count} trades were")
            .' passed over, on a market with no asset or not priced in AUD: '.implode(', ', $markets).'.';
    }
}
