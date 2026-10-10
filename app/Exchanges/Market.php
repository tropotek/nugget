<?php

namespace App\Exchanges;

/**
 * A market on an exchange: `id` is the exchange's own name for it
 * ("BTC-AUD"), where `base` (BTC) is traded and priced in `quote` (AUD).
 */
final readonly class Market
{
    public function __construct(public string $id, public string $base, public string $quote) {}

    /**
     * Whether its prices are in AUD, so it can price an asset.
     */
    public function isInAud(): bool
    {
        return $this->quote === 'AUD';
    }
}
