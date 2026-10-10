<?php

namespace App\Enums;

enum TransactionType: string
{
    case Buy = 'buy';
    case Sell = 'sell';

    /** Acquired at no cost, such as prospected gold. */
    case Found = 'found';

    /** A staking reward or an airdrop. */
    case Reward = 'reward';

    /** Moved between the user's own holdings, with no gain or loss. */
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';

    /** A correction to match a real balance. Its units are signed. */
    case Adjust = 'adjust';

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->value));
    }

    /**
     * Whether the type has a unit price. The others are saved with a price of zero.
     */
    public function hasPrice(): bool
    {
        return in_array($this, [self::Buy, self::Sell, self::Reward], true);
    }

    public function hasFee(): bool
    {
        return in_array($this, [self::Buy, self::Sell], true);
    }

    /**
     * Whether the type has an AUD total: every one but a transfer out, whose
     * units keep the cost they already have.
     */
    public function hasTotal(): bool
    {
        return $this !== self::TransferOut;
    }

    public function addsUnits(): bool
    {
        return in_array($this, [self::Buy, self::Found, self::Reward, self::TransferIn], true);
    }

    public function removesUnits(): bool
    {
        return in_array($this, [self::Sell, self::TransferOut], true);
    }
}
