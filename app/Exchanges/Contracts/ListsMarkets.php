<?php

namespace App\Exchanges\Contracts;

use App\Exchanges\Market;
use Throwable;

/**
 * An exchange that says which markets it has.
 */
interface ListsMarkets
{
    /**
     * Every market on the exchange, as it lists them. Read through
     * App\Exchanges\Markets, which keeps the list for a day.
     *
     * @return list<Market>
     *
     * @throws Throwable when the exchange cannot be reached
     */
    public function markets(): array;
}
