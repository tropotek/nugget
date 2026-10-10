<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Models\Contracts\Owned;
use App\Support\Decimal;
use Database\Factories\ParcelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A lot of units acquired together. `cost_base` is the AUD cost of the whole
 * lot, and `units_remaining` is what later sales and transfers have left.
 *
 * Parcels are worked out from the transactions by the parcel matcher, which
 * is the only thing that writes them. `acquired_at` is kept apart from the
 * transaction's time because a transfer in carries the original date.
 */
#[Fillable(['asset_id', 'transaction_id', 'acquired_at', 'units', 'units_remaining', 'cost_base', 'estimated'])]
class Parcel extends Model implements Owned
{
    /** @use HasFactory<ParcelFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'estimated' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (Parcel $parcel) {
            $parcel->asset_id ??= $parcel->transaction->asset_id;
            $parcel->check();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acquired_at' => 'datetime',
            'units' => DecimalCast::units(),
            'units_remaining' => DecimalCast::units(),
            'cost_base' => DecimalCast::aud(),
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

    /**
     * The transaction that acquired the parcel.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * The sales that have used part of the parcel.
     *
     * @return HasMany<Disposal, $this>
     */
    public function disposals(): HasMany
    {
        return $this->hasMany(Disposal::class);
    }

    /**
     * Parcels with units left.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('units_remaining', '!=', Decimal::zero()->toFixed(Decimal::UNITS_SCALE));
    }

    public function ownerId(): int
    {
        return $this->asset->user_id;
    }

    public function isOpen(): bool
    {
        return $this->units_remaining->isPositive();
    }

    /**
     * The share of the cost base that belongs to the units left.
     */
    public function remainingCostBase(): Decimal
    {
        return $this->cost_base->mul($this->units_remaining)->div($this->units, Decimal::AUD_SCALE);
    }

    private function check(): void
    {
        if ($this->asset_id !== $this->transaction->asset_id) {
            throw new LogicException('A parcel is on the same asset as the transaction that acquired it.');
        }

        if (! $this->units->isPositive()) {
            throw new LogicException('A parcel needs units above zero.');
        }

        if ($this->units_remaining->isNegative() || $this->units_remaining->greaterThan($this->units)) {
            throw new LogicException("A parcel's units remaining are between zero and its units.");
        }

        if ($this->cost_base->isNegative()) {
            throw new LogicException("A parcel's cost base cannot be negative.");
        }
    }
}
