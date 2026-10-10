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

/**
 * A user's account on an exchange, reached through the ccxt driver named in
 * `driver`.
 *
 * The owner is set through the user's relation, and the balance, last sync
 * time and last error are written by the sync, so none of them is fillable.
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

    public function ownerId(): int
    {
        return $this->user_id;
    }
}
