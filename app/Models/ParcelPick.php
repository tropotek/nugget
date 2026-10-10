<?php

namespace App\Models;

use App\Casts\DecimalCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Units of one parcel that a sale was matched to by hand.
 *
 * The parcel is named by the transaction that acquired it and its acquired
 * date, not by its id, because a rematch gives every parcel a new id.
 * App\Ledger\Ledger writes these; the matcher reads them.
 */
#[Fillable(['source_transaction_id', 'acquired_at', 'units'])]
class ParcelPick extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acquired_at' => 'datetime',
            'units' => DecimalCast::units(),
        ];
    }

    /**
     * The sale.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * The transaction that acquired the picked parcel.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'source_transaction_id');
    }
}
