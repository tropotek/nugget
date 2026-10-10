<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Models\Contracts\Owned;
use App\Support\Decimal;
use App\Support\FinancialYear;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a user has entered for the tax report of one financial year: the
 * capital losses carried forward into it from earlier years. `year` is the
 * year it ends in. A year with no row has none.
 *
 * The owner is set through the user's relation, so it is not fillable.
 */
#[Fillable(['year', 'losses_carried_forward'])]
class TaxYear extends Model implements Owned
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'losses_carried_forward' => '0.00',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'losses_carried_forward' => DecimalCast::aud(),
        ];
    }

    /**
     * The losses a user carried forward into a year: zero when none are saved.
     */
    public static function lossesCarriedInto(User $user, FinancialYear $year): Decimal
    {
        return $user->taxYears()->where('year', $year->year)->first()?->losses_carried_forward ?? Decimal::zero();
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
