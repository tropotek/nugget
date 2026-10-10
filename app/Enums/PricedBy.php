<?php

namespace App\Enums;

/**
 * Where an asset's price comes from.
 */
enum PricedBy
{
    /** A market on one of the user's exchanges. */
    case Exchange;

    /** A symbol on the price feed, such as XAU/AUD. */
    case Feed;

    /** The asset has no price. */
    case Nothing;
}
