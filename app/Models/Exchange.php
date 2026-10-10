<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Models\Contracts\Owned;
use Database\Factories\ExchangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's account on an exchange. `driver` names the App\Exchanges driver
 * that talks to it (see App\Exchanges\ExchangeDrivers).
 *
 * The owner is set through the user's relation, and the AUD balance, last
 * sync time, last error, last sync note and trade cursor (the exchange's id
 * of the last trade read) are written by the sync, and `trades_after` (the
 * moment its history is already in up to) by the backfill
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
            'trades_after' => 'datetime',
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
     * The assets held on the exchange, by market id: the ones its trades are
     * stored on and its balances compared with. Where two assets share a
     * market, such as a holding and a cold wallet priced by the same one, it
     * is the one added first.
     *
     * @return Collection<string, Asset>
     */
    public function tradedAssets(): Collection
    {
        return $this->assets()->orderBy('id')->get()->unique('market_id')->keyBy('market_id');
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
