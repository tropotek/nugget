<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Models\Contracts\Owned;
use Database\Factories\DisposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The part of one parcel that one sale used up: a line of the capital gains
 * report. `gain` is the proceeds less the cost base, and is negative for a
 * loss. `long_held` says the parcel was held longer than 12 months.
 *
 * Disposals are worked out from the transactions by the parcel matcher, which
 * is the only thing that writes them.
 */
#[Fillable(['transaction_id', 'parcel_id', 'units', 'cost_base', 'proceeds', 'gain', 'long_held'])]
class Disposal extends Model implements Owned
{
    /** @use HasFactory<DisposalFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'long_held' => false,
    ];

    protected static function booted(): void
    {
        static::saving(fn (Disposal $disposal) => $disposal->check());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'units' => DecimalCast::units(),
            'cost_base' => DecimalCast::aud(),
            'proceeds' => DecimalCast::aud(),
            'gain' => DecimalCast::aud(),
            'long_held' => 'boolean',
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
     * @return BelongsTo<Parcel, $this>
     */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }

    public function ownerId(): int
    {
        return $this->parcel->asset->user_id;
    }

    private function check(): void
    {
        if ($this->parcel->asset_id !== $this->transaction->asset_id) {
            throw new LogicException('A disposal joins a sale to a parcel of the same asset.');
        }

        if (! $this->units->isPositive()) {
            throw new LogicException('A disposal needs units above zero.');
        }

        if ($this->cost_base->isNegative() || $this->proceeds->isNegative()) {
            throw new LogicException("A disposal's cost base and proceeds cannot be negative.");
        }

        if (! $this->gain->equals($this->proceeds->sub($this->cost_base))) {
            throw new LogicException("A disposal's gain is its proceeds less its cost base.");
        }
    }
}
