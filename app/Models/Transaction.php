<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Enums\ParcelMatching;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Contracts\Owned;
use App\Support\Decimal;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One line of the ledger: something that happened to an asset.
 *
 * `units` is how many, always positive except on an adjustment, where the
 * sign says which way. `total` is the AUD cost with the fee for units coming
 * in, and the AUD proceeds less the fee for units going out.
 *
 * `matching` is the method a sale was matched by, kept so that a rematch
 * gives it the same parcels. The matcher sets it; it is not fillable, and nor
 * is the asset, which is set through its relation.
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
            'matching' => ParcelMatching::class,
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The parcels this acquired. A transfer in can bring several.
     *
     * @return HasMany<Parcel, $this>
     */
    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class);
    }

    /**
     * The parcels this used up, when it is a sale.
     *
     * @return HasMany<Disposal, $this>
     */
    public function disposals(): HasMany
    {
        return $this->hasMany(Disposal::class);
    }

    /**
     * The parcels a sale was matched to by hand.
     *
     * @return HasMany<ParcelPick, $this>
     */
    public function picks(): HasMany
    {
        return $this->hasMany(ParcelPick::class);
    }

    public function ownerId(): int
    {
        return $this->asset->user_id;
    }

    /**
     * A trade fetched from an exchange is the exchange's record: only its notes change.
     */
    public function isLocked(): bool
    {
        return $this->source === TransactionSource::Exchange;
    }

    /**
     * The total a transaction has when none is given: the cost with the fee
     * for a buy, the proceeds less the fee for a sale, the market value of a
     * reward, and nothing for the rest.
     */
    public static function totalOf(TransactionType $type, Decimal $units, Decimal $unitPrice, Decimal $fee): Decimal
    {
        $value = $units->mul($unitPrice);

        $total = match ($type) {
            TransactionType::Buy => $value->add($fee),
            TransactionType::Sell => $value->sub($fee),
            TransactionType::Reward => $value,
            default => Decimal::zero(),
        };

        return $total->round(Decimal::AUD_SCALE);
    }

    public function isHandMatched(): bool
    {
        return $this->picks->isNotEmpty();
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
