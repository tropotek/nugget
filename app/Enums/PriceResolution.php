<?php

namespace App\Enums;

/**
 * How far apart an asset's stored prices are.
 */
enum PriceResolution: string
{
    /** The last price of a calendar day in the owner's timezone. Kept for good. */
    case Day = 'day';

    /** A price at the moment it was fetched. Pruned after a couple of days. */
    case Intraday = 'intraday';
}
