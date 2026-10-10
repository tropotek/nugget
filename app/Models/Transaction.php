<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Contracts\Owned;
use App\Support\Decimal;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of the ledger: something that happened to an asset.
 *
 * `units` is how many, always positive except on an adjustment, where the
 * sign says which way. `total` is the AUD cost with the fee for units coming
 * in, and the AUD proceeds less the fee for units going out.
 *
 * The asset is set through its relation, so it is not fillable.
 */
#[Fillable(['type', 'occurred_at', 'units', 'unit_price', 'fee', 'total', 'source', 'external_id', 'estimated', 'notes'])]
class Transaction extends Model implements Owned
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'unit_price' => '0.00000000',
        'fee' => '0.00',
        'total' => '0.00',
        'source' => 'manual',
        'estimated' => false,
    ];

    protected static function booted(): void
    {
        static::saving(fn (Transaction $transaction) => $transaction->checkAmounts());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'occurred_at' => 'datetime',
            'units' => DecimalCast::units(),
            'unit_price' => DecimalCast::price(),
            'fee' => DecimalCast::aud(),
            'total' => DecimalCast::aud(),
            'source' => TransactionSource::class,
            'estimated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function ownerId(): int
    {
        return $this->asset->user_id;
    }

    /**
     * The change this makes to the units held: negative for a sale or a transfer out.
     */
    public function signedUnits(): Decimal
    {
        return $this->type->removesUnits() ? $this->units->negate() : $this->units;
    }

    private function checkAmounts(): void
    {
        if ($this->units->isZero() || ($this->units->isNegative() && $this->type !== TransactionType::Adjust)) {
            throw new LogicException('A transaction needs units above zero. Only an adjustment may be negative.');
        }

        foreach (['unit_price', 'fee', 'total'] as $amount) {
            if ($this->{$amount}->isNegative()) {
                throw new LogicException("A transaction's {$amount} cannot be negative.");
            }
        }
    }
}
