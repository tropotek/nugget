<?php

namespace App\Notifications;

use App\Enums\TransactionSource;
use App\Models\Exchange;
use App\Notifications\Contracts\Presentable;
use Illuminate\Notifications\Notification;

/**
 * A sync stored trades from an exchange. There is one for a sync, however
 * many trades it found, so reading an account's whole history does not send
 * hundreds. It is kept in the database only.
 */
class NewTrades extends Notification implements Presentable
{
    /**
     * @param  list<array{asset_id: int, asset: string, count: int}>  $assets  the trades stored on each asset
     */
    public function __construct(private readonly Exchange $exchange, private readonly array $assets) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'exchange_id' => $this->exchange->id,
            'exchange' => $this->exchange->label,
            'count' => array_sum(array_column($this->assets, 'count')),
            'assets' => $this->assets,
        ];
    }

    public static function present(array $data): Presented
    {
        $assets = $data['assets'] ?? [];
        $count = (int) ($data['count'] ?? 0);

        return new Presented(
            $count === 1 ? '1 new trade' : "{$count} new trades",
            "On {$data['exchange']}: ".implode(', ', array_map(fn (array $asset) => "{$asset['count']} {$asset['asset']}", $assets)).'.',
            route('transactions.index', array_filter([
                'source' => TransactionSource::Exchange->value,
                'asset' => count($assets) === 1 ? $assets[0]['asset_id'] : null,
            ])),
            'ri-exchange-line',
        );
    }
}
