<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Models\Contracts\Owned;
use Database\Factories\ExchangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's account on an exchange. `driver` names the App\Exchanges driver
 * that talks to it (see App\Exchanges\ExchangeDrivers).
 *
 * The owner is set through the user's relation, and the AUD balance, last
 * sync time, last error and last sync note are written by the sync
 * (App\Exchanges\Sync\ExchangeSync), so none of them is fillable.
 */
#[Fillable(['driver', 'label', 'api_key', 'secret', 'active'])]
#[Hidden(['api_key', 'secret'])]
class Exchange extends Model implements Owned
{
    /** @use HasFactory<ExchangeFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'aud_balance' => '0.00',
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'secret' => 'encrypted',
            'aud_balance' => DecimalCast::aud(),
            'active' => 'boolean',
            'last_synced_at' => 'datetime',
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
     * The assets priced by a market on this exchange.
     *
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * What the exchange said the account held at the last sync, AUD included.
     *
     * @return HasMany<ExchangeBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(ExchangeBalance::class);
    }

    public function ownerId(): int
    {
        return $this->user_id;
    }
}
