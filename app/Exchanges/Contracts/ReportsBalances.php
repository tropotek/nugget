<?php

namespace App\Exchanges\Contracts;

use App\Exchanges\Balance;
use Throwable;

/**
 * An exchange that says what the account holds.
 */
interface ReportsBalances
{
    /**
     * The account's balance of each symbol, AUD among them. A symbol the
     * account holds none of may be listed at zero or left out.
     *
     * @return list<Balance>
     *
     * @throws Throwable when the exchange cannot be reached or refuses the key
     */
    public function balances(): array;
}
