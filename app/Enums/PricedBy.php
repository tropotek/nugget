<?php

namespace App\Enums;

/**
 * Where an asset's price comes from.
 */
enum PricedBy: string
{
    /** A market on one of the user's exchanges. */
    case Exchange = 'exchange';

    /** A symbol on the price feed, such as XAU/AUD. */
    case Feed = 'feed';

    /** The asset has no price. */
    case Nothing = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Exchange => 'An exchange market',
            self::Feed => 'The price feed',
            self::Nothing => 'No price',
        };
    }
}
