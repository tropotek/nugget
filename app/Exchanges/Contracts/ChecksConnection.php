<?php

namespace App\Exchanges\Contracts;

use Throwable;

/**
 * An exchange whose key and secret can be tried out.
 */
interface ChecksConnection
{
    /**
     * Make one call that needs the account's key and secret. It returns when
     * the exchange accepts them and changes nothing on the exchange.
     *
     * @throws Throwable when the exchange cannot be reached or refuses the key
     */
    public function checkConnection(): void;
}
