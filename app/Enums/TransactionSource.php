<?php

namespace App\Enums;

/**
 * How a transaction got into the ledger.
 */
enum TransactionSource: string
{
    /** Entered by hand. */
    case Manual = 'manual';

    /** Fetched from an exchange by the sync. */
    case Exchange = 'exchange';

    /** Brought over from tkbtc or a transaction report. */
    case Import = 'import';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
