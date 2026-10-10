<?php

namespace App\Api;

use App\Models\Parcel;
use App\Models\User;
use App\Notifications\BalanceMismatch;
use App\Support\NotificationFeed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

/**
 * What a user has left to sort out before the figures can be relied on.
 */
class IssuesData
{
    /**
     * The balance mismatches the last syncs found, and the open parcels
     * with no cost base. A parcel that was found (prospected gold) has no
     * cost on purpose; one that came any other way is missing its cost.
     *
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $mismatches = NotificationFeed::of($user)->where('type', BalanceMismatch::class)->get();

        $costless = Parcel::query()
            ->open()
            ->whereHas('asset', fn (Builder $asset) => $asset->where('user_id', $user->id))
            ->with(['asset', 'transaction'])
            ->orderBy('acquired_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Parcel $parcel) => $parcel->cost_base->isZero())
            ->values();

        return [
            'balance_mismatches' => $mismatches->map(fn (DatabaseNotification $mismatch) => [
                'exchange' => ['id' => $mismatch->data['exchange_id'], 'label' => $mismatch->data['exchange']],
                'asset' => ['id' => $mismatch->data['asset_id'], 'name' => $mismatch->data['asset'], 'unit' => $mismatch->data['unit']],
                'units_in_ledger' => $mismatch->data['ledger'],
                'units_on_exchange' => $mismatch->data['on_exchange'],
                // What the exchange has over the ledger: negative when the ledger has more.
                'difference' => $mismatch->data['difference'],
                'found_at' => Format::date($mismatch->updated_at, $user),
            ])->all(),
            'parcels_without_cost' => $costless->map(fn (Parcel $parcel) => [
                'asset' => Format::asset($parcel->asset),
                'acquired_at' => Format::date($parcel->acquired_at, $user),
                'units_remaining' => Format::number($parcel->units_remaining),
                'acquired_by' => $parcel->transaction->type->value,
                'estimated' => $parcel->estimated,
                'transaction_id' => $parcel->transaction_id,
            ])->all(),
        ];
    }
}
