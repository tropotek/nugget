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
 * no losses and takes in the default classes
 * (`nugget.tax.default_asset_classes`: crypto only).
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
     * The asset classes a user's report for a year takes in: the default
     * ones when none are chosen.
     *
     * @return list<AssetClass>
     */
    public static function classesIn(User $user, FinancialYear $year): array
    {
        return $user->taxYears()->where('year', $year->year)->first()?->classes() ?? self::defaultClasses();
    }

    /**
     * The classes a report takes in until its user chooses: crypto only,
     * unless the config says otherwise.
     *
     * @return list<AssetClass>
     */
    public static function defaultClasses(): array
    {
        return self::inOrder((array) config('nugget.tax.default_asset_classes'));
    }

    /**
     * The classes this year's report takes in, in the order of AssetClass.
     *
     * @return list<AssetClass>
     */
    public function classes(): array
    {
        return $this->asset_classes === null ? self::defaultClasses() : self::inOrder($this->asset_classes);
    }

    /**
     * @param  array<int, mixed>  $values  AssetClass values, in any order
     * @return list<AssetClass>
     */
    private static function inOrder(array $values): array
    {
        return array_values(array_filter(AssetClass::cases(), fn (AssetClass $class) => in_array($class->value, $values, true)));
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
