<?php

namespace App\Ledger;

use App\Models\ParcelPick;
use App\Models\Transaction;
use App\Support\Decimal;
use App\Support\LocalTime;
use DateTimeInterface;
use RuntimeException;

/**
 * A sale matched by hand cannot be given the parcels that were picked for it.
 */
class InvalidParcelPick extends RuntimeException
{
    public static function notASale(Transaction $transaction): self
    {
        return new self(self::subject($transaction).' is not a sale, so it cannot be matched to parcels by hand.');
    }

    public static function otherAsset(Transaction $sale): self
    {
        return new self(self::subject($sale).' can only be matched to parcels of the same asset.');
    }

    public static function wrongTotal(Transaction $sale, Decimal $picked): self
    {
        $unit = $sale->asset->unit;

        return new self(self::subject($sale)." is of {$sale->units} {$unit}, but the parcels picked for it add up to {$picked} {$unit}.");
    }

    public static function unavailable(Transaction $sale, ParcelPick $pick): self
    {
        $acquired = self::date($pick->acquired_at, $sale);

        return new self(
            self::subject($sale)." was matched by hand to {$pick->units} {$sale->asset->unit} of the parcel acquired on {$acquired}, which no longer has them.",
        );
    }

    private static function subject(Transaction $transaction): string
    {
        $type = strtolower($transaction->type->label());

        return "{$transaction->asset->name}: the {$type} on ".self::date($transaction->occurred_at, $transaction);
    }

    private static function date(DateTimeInterface $date, Transaction $transaction): string
    {
        return LocalTime::of($date, $transaction->asset->user)->format('j M Y');
    }
}
