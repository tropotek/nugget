<?php

namespace App\Models;

use App\Casts\DecimalCast;
use App\Enums\PriceResolution;
use App\Models\Contracts\Owned;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Database\Factories\PriceFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The AUD price of one unit of an asset: what it would sell for (`bid`) and
 * what it would cost to buy (`ask`).
 *
 * An intraday row's `at` is the moment the price was fetched. A day row is
 * one per calendar day in the owner's timezone and its `at` is that date at
 * 00:00:00: a date, not a moment, so read it with `$price->at->toDateString()`.
 *
 * Each fetch writes an intraday row and replaces the day's day row, so an
 * asset's newest day row holds its latest price, and that row's `updated_at`
 * is when it was fetched.
 *
 * The asset is set through its relation, so it is not fillable.
 */
#[Fillable(['at', 'bid', 'ask', 'resolution'])]
class Price extends Model implements Owned
{
    /** @use HasFactory<PriceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(fn (Price $price) => $price->check());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'at' => 'datetime',
            'bid' => DecimalCast::price(),
            'ask' => DecimalCast::price(),
            'resolution' => PriceResolution::class,
        ];
    }

    /**
     * The `at` of the day row for a date. A moment is read as its date in the
     * timezone given, or in its own when none is.
     */
    public static function day(DateTimeInterface|string $date, ?string $timezone = null): CarbonImmutable
    {
        if ($date instanceof DateTimeInterface) {
            $date = ($timezone ? CarbonImmutable::instance($date)->setTimezone($timezone) : $date)->format('Y-m-d');
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The latest price of each asset: its newest day row.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLatestPerAsset(Builder $query): void
    {
        $query->where('prices.resolution', PriceResolution::Day)->whereRaw(
            'prices.at = (select max(newest.at) from prices as newest where newest.asset_id = prices.asset_id and newest.resolution = ?)',
            [PriceResolution::Day->value],
        );
    }

    /**
     * The day rows from one date to another, both included, oldest first.
     * The dates are as `day()` takes them.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDaily(Builder $query, DateTimeInterface|string $from, DateTimeInterface|string $to): void
    {
        $query->where('prices.resolution', PriceResolution::Day)
            ->whereBetween('prices.at', [self::day($from), self::day($to)])
            ->orderBy('prices.at');
    }

    /**
     * The intraday rows from one moment to another, both included, oldest first.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeIntraday(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $query->where('prices.resolution', PriceResolution::Intraday)
            ->whereBetween('prices.at', [CarbonImmutable::instance($from)->utc(), CarbonImmutable::instance($to)->utc()])
            ->orderBy('prices.at');
    }

    public function ownerId(): int
    {
        return $this->asset->user_id;
    }

    /**
     * Halfway between the bid and the ask.
     */
    public function mid(): Decimal
    {
        return $this->bid->add($this->ask)->div(2, Decimal::PRICE_SCALE);
    }

    private function check(): void
    {
        if ($this->bid->isNegative() || $this->ask->isNegative()) {
            throw new LogicException('A price cannot be negative.');
        }

        if ($this->resolution === PriceResolution::Day && ! $this->at->equalTo(self::day($this->at))) {
            throw new LogicException("A day price's time is its date at 00:00:00. Make it with Price::day().");
        }
    }
}
