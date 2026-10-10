<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Enums\AssetClass;
use App\Models\Contracts\Owned;
use App\Support\Decimal;
use App\Support\FinancialYear;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a user has chosen for the tax report of one financial year: the
 * capital losses carried forward into it from earlier years, and the asset
 * classes it takes in. `year` is the year it ends in. A year with no row has
 * no losses and takes in every class.
 *
 * The owner is set through the user's relation, so it is not fillable.
 */
#[Fillable(['year', 'losses_carried_forward', 'asset_classes'])]
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
            'asset_classes' => 'array',
        ];
    }

    /**
     * The asset classes a user's report for a year takes in: every class
     * when none are chosen.
     *
     * @return list<AssetClass>
     */
    public static function classesIn(User $user, FinancialYear $year): array
    {
        return $user->taxYears()->where('year', $year->year)->first()?->classes() ?? AssetClass::cases();
    }

    /**
     * The classes this year's report takes in, in the order of AssetClass.
     *
     * @return list<AssetClass>
     */
    public function classes(): array
    {
        $chosen = $this->asset_classes;

        return $chosen === null
            ? AssetClass::cases()
            : array_values(array_filter(AssetClass::cases(), fn (AssetClass $class) => in_array($class->value, $chosen, true)));
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
