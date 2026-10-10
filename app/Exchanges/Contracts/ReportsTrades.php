<?php

namespace App\Exchanges\Contracts;

use App\Exchanges\Trade;
use Throwable;

/**
 * An exchange that lists the account's trades, and can be asked for those
 * after one it has given before. The same call reaches back through the
 * account's whole history.
 */
interface ReportsTrades
{
    /**
     * The next page of the account's trades after the one with this id,
     * oldest first: from the first trade when the id is null, and empty when
     * there are no more. Ask again with the id of the last one to go on.
     *
     * @return list<Trade>
     *
     * @throws Throwable when the exchange cannot be reached or refuses the key
     */
    public function tradesAfter(?string $tradeId): array;
}
