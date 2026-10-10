<?php

namespace App\Models;

use App\Enums\AssetClass;
use App\Enums\PricedBy;
use App\Models\Contracts\Owned;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A thing a user holds, counted in `unit` (BTC, oz, g). It carries its own
 * price source: a market on one of the user's exchanges, a feed symbol, or
 * neither for an asset with no price.
 *
 * The owner is set through the user's relation, so it is not fillable.
 */
#[Fillable(['name', 'symbol', 'class', 'unit', 'exchange_id', 'market_id', 'feed_symbol', 'in_total', 'notes'])]
class Asset extends Model implements Owned
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'in_total' => true,
    ];

    protected static function booted(): void
    {
        static::saving(fn (Asset $asset) => $asset->checkPriceSource());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class' => AssetClass::class,
            'in_total' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
        return $this->user_id;
    }

    public function pricedBy(): PricedBy
    {
        return match (true) {
            $this->exchange_id !== null => PricedBy::Exchange,
            $this->feed_symbol !== null => PricedBy::Feed,
            default => PricedBy::Nothing,
        };
    }

    /**
     * An exchange and a market go together, on an exchange of the same user,
     * and an asset priced by a market has no feed symbol.
     */
    private function checkPriceSource(): void
    {
        if (($this->exchange_id === null) !== ($this->market_id === null)) {
            throw new LogicException("Asset '{$this->name}' needs both an exchange and a market, or neither.");
        }

        if ($this->exchange_id !== null && $this->feed_symbol !== null) {
            throw new LogicException("Asset '{$this->name}' is priced by a market or by a feed symbol, not both.");
        }

        if ($this->exchange_id !== null && $this->exchange?->user_id !== $this->user_id) {
            throw new LogicException("Asset '{$this->name}' can only use an exchange of its own user.");
        }
    }
}
