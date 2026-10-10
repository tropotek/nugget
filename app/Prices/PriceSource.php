<?php

namespace App\Prices;

use App\Models\Asset;

/**
 * Somewhere the current price of an asset can be read from.
 */
interface PriceSource
{
    /**
     * The asset's price now, or null when it cannot be had. A failure is
     * logged and never thrown.
     */
    public function quote(Asset $asset): ?Quote;
}
