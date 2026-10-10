<?php

namespace App\Ledger;

use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;

/**
 * A search of one user's transactions: the filters and the order.
 *
 * `from` and `to` are days in the user's timezone, and both are included.
 */
final readonly class TransactionSearch
{
    /** The columns the list can be ordered by. */
    public const SORTS = ['date', 'asset', 'type', 'units', 'total'];

    public function __construct(
        public User $user,
        public ?int $assetId = null,
        public ?TransactionType $type = null,
        public ?TransactionSource $source = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?bool $estimated = null,
        public string $sort = 'date',
        public bool $descending = true,
    ) {}

    /**
     * Read a search from a query string: `asset`, `type`, `source`, `from`,
     * `to`, `estimated`, `sort` and `dir`. Anything not understood is passed
     * over, another user's asset included.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, User $user): self
    {
        $text = fn (string $key) => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
        $sort = in_array($text('sort'), self::SORTS, true) ? $text('sort') : 'date';

        return new self(
            user: $user,
            assetId: $user->assets()->whereKey((int) $text('asset'))->value('id'),
            type: TransactionType::tryFrom($text('type')),
            source: TransactionSource::tryFrom($text('source')),
            from: self::day($text('from'), $user),
            to: self::day($text('to'), $user),
            estimated: match ($text('estimated')) {
                '1' => true,
                '0' => false,
                default => null,
            },
            sort: $sort,
            descending: match ($text('dir')) {
                'asc' => false,
                'desc' => true,
                default => $sort === 'date',
            },
        );
    }

    /**
     * The user's transactions that match, in order, each with its asset.
     *
     * @return Builder<Transaction>
     */
    public function query(): Builder
    {
        $direction = $this->descending ? 'desc' : 'asc';

        // Units and totals are strings, so they are ordered by their value as a number. Never read an amount this way.
        $order = match ($this->sort) {
            'asset' => 'assets.name',
            'type' => 'transactions.type',
            'units' => 'CAST(transactions.units AS REAL)',
            'total' => 'CAST(transactions.total AS REAL)',
            default => 'transactions.occurred_at',
        };

        return Transaction::query()
            ->select('transactions.*')
            ->join('assets', 'assets.id', '=', 'transactions.asset_id')
            ->where('assets.user_id', $this->user->id)
            ->with('asset')
            ->when($this->assetId, fn (Builder $query) => $query->where('transactions.asset_id', $this->assetId))
            ->when($this->type, fn (Builder $query) => $query->where('transactions.type', $this->type))
            ->when($this->source, fn (Builder $query) => $query->where('transactions.source', $this->source))
            ->when($this->from, fn (Builder $query) => $query->where('transactions.occurred_at', '>=', $this->from->utc()))
            ->when($this->to, fn (Builder $query) => $query->where('transactions.occurred_at', '<', $this->to->addDay()->utc()))
            ->when($this->estimated !== null, fn (Builder $query) => $query->where('transactions.estimated', $this->estimated))
            ->orderByRaw("{$order} {$direction}")
            ->orderBy('transactions.occurred_at', $direction)
            ->orderBy('transactions.id', $direction);
    }

    public function isFiltered(): bool
    {
        return $this->filters() !== [];
    }

    /**
     * The search as a query string, for a link that keeps it. Defaults are left out.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function toQuery(array $changes = []): array
    {
        $isDefaultOrder = $this->sort === 'date' && $this->descending;

        return [
            ...$this->filters(),
            ...($isDefaultOrder ? [] : ['sort' => $this->sort, 'dir' => $this->descending ? 'desc' : 'asc']),
            ...$changes,
        ];
    }

    /**
     * The query string that orders by a column: its usual way first (newest
     * or A to Z or smallest), and the other way when it is already the order.
     *
     * @return array<string, mixed>
     */
    public function sortedBy(string $sort): array
    {
        $descending = $this->sort === $sort ? ! $this->descending : $sort === 'date';

        return $this->toQuery(['sort' => $sort, 'dir' => $descending ? 'desc' : 'asc']);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return array_filter([
            'asset' => $this->assetId,
            'type' => $this->type?->value,
            'source' => $this->source?->value,
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'estimated' => $this->estimated === null ? null : (string) (int) $this->estimated,
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * The start of a day typed as 2025-06-30, in the user's timezone.
     */
    private static function day(string $date, User $user): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $date, $user->timezone) ?: null;
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
