<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Models\Contracts\Owned;
use Database\Factories\ExchangeBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an exchange said the account held of one symbol at its last sync:
 * `units` in all, of which `available` is not tied up in open orders. AUD
 * is a symbol like any other.
 *
 * It is the exchange's figure, not the ledger's. Only the sync
 * (App\Exchanges\Sync\SyncBalances) writes these, replacing them all each
 * time, so an id does not last. A symbol with no row was not held.
 */
#[Fillable(['symbol', 'units', 'available'])]
class ExchangeBalance extends Model implements Owned
{
    /** @use HasFactory<ExchangeBalanceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => DecimalCast::units(),
            'available' => DecimalCast::units(),
        ];
    }

    /**
     * @return BelongsTo<Exchange, $this>
     */
    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    public function ownerId(): int
    {
        return $this->exchange->user_id;
    }
}
