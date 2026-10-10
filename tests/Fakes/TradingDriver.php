<?php

namespace Tests\Fakes;

use App\Exchanges\Contracts\ReportsTrades;
use App\Exchanges\ExchangeDriver;
use App\Exchanges\Trade;
use App\Models\Exchange;

/**
 * A driver that reports the trades it is handed and nothing else, for what
 * no recorded response of a real exchange shows: a fee charged in the coin,
 * or more pages than one sync reads. Set `$trades` before the sync.
 */
class TradingDriver implements ExchangeDriver, ReportsTrades
{
    /** @var list<Trade> every trade on the account, oldest first */
    public static array $trades = [];

    public static int $perPage = 2;

    public function __construct(public readonly Exchange $exchange) {}

    public static function label(): string
    {
        return 'A trading exchange';
    }

    public function tradesAfter(?string $tradeId): array
    {
        $after = $tradeId === null ? 0 : array_search($tradeId, array_column(self::$trades, 'id'), true) + 1;

        return array_slice(self::$trades, $after, self::$perPage);
    }
}
