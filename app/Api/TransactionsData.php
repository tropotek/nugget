<?php

namespace App\Api;

use App\Ledger\TransactionSearch;
use App\Models\Transaction;
use App\Models\User;

/**
 * A page of a user's ledger.
 */
class TransactionsData
{
    public const PER_PAGE = 50;

    public const MOST_PER_PAGE = 200;

    /**
     * The transactions that match, the newest first unless asked otherwise.
     *
     * The filters are those of the transaction list: `asset` (an id), `type`,
     * `source`, `from` and `to` (days as 2025-06-30 in the user's timezone,
     * both included), `estimated` (1 or 0), `sort` and `dir`. One that is not
     * understood is passed over. `page` and `per_page` choose the page.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function for(User $user, array $input): array
    {
        $search = TransactionSearch::fromInput($input, $user);
        $whole = fn (string $key) => is_scalar($input[$key] ?? null) ? (int) $input[$key] : 0;
        $perPage = $whole('per_page') >= 1 ? min(self::MOST_PER_PAGE, $whole('per_page')) : self::PER_PAGE;
        $page = max(1, $whole('page'));

        $found = $search->query()->paginate($perPage, ['*'], 'page', $page);

        return [
            'transactions' => collect($found->items())->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'asset' => Format::asset($transaction->asset),
                'type' => $transaction->type->value,
                'occurred_at' => Format::date($transaction->occurred_at, $user),
                'units' => Format::number($transaction->units),
                // The change to the holding: negative for a sale or a transfer out.
                'units_change' => Format::number($transaction->signedUnits()),
                'unit_price' => Format::number($transaction->unit_price),
                'fee' => Format::aud($transaction->fee),
                'total' => Format::aud($transaction->total),
                'source' => $transaction->source->value,
                'external_id' => $transaction->external_id,
                'estimated' => $transaction->estimated,
                'notes' => $transaction->notes,
            ])->all(),
            'filters' => $search->toQuery(),
            'page' => $found->currentPage(),
            'per_page' => $found->perPage(),
            'pages' => $found->lastPage(),
            'total' => $found->total(),
        ];
    }
}
