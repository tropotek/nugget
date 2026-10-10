<?php

namespace App\Notifications;

use App\Enums\TransactionType;
use App\Ledger\Holding;
use App\Models\Exchange;
use App\Notifications\Contracts\Presentable;
use App\Support\Decimal;
use Illuminate\Notifications\Notification;

/**
 * An exchange says the account holds a different number of units of an asset
 * than the ledger does. It is kept in the database only.
 *
 * There is one for an asset for as long as the two differ: the sync
 * (App\Exchanges\Sync\ReconcileBalances) updates it when the figures move
 * and deletes it when they agree again. Nothing changes the ledger: the
 * owner settles it with a transfer or an adjustment, which `links()` leads to.
 */
class BalanceMismatch extends Notification implements Presentable
{
    public function __construct(
        private readonly Exchange $exchange,
        private readonly Holding $holding,
        private readonly Decimal $onExchange,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * The figures as decimal strings. `difference` is what the exchange has
     * over the ledger: negative when the ledger has more.
     *
     * @return array<string, int|string>
     */
    public function toArray(object $notifiable): array
    {
        $asset = $this->holding->asset;

        return [
            'exchange_id' => $this->exchange->id,
            'exchange' => $this->exchange->label,
            'asset_id' => $asset->id,
            'asset' => $asset->name,
            'unit' => $asset->unit,
            'ledger' => (string) $this->holding->units,
            'on_exchange' => (string) $this->onExchange,
            'difference' => (string) $this->onExchange->sub($this->holding->units),
        ];
    }

    public static function present(array $data): Presented
    {
        $difference = Decimal::of((string) $data['difference']);

        return new Presented(
            "{$data['asset']} does not match {$data['exchange']}",
            "{$data['exchange']} has {$difference->abs()} {$data['unit']} ".($difference->isNegative() ? 'less' : 'more').' than the ledger.',
            route('exchanges.edit', $data['exchange_id']).'#balance-mismatches',
            'ri-error-warning-line',
        );
    }

    /**
     * The two ways to settle a mismatch, each a transaction form filled in
     * with the asset and the units: a transfer, for units that came from or
     * went to another holding, and an adjustment, for a plain correction.
     *
     * @param  array<string, mixed>  $data  as `toArray()` gives it
     * @return array{transfer: string, transferLabel: string, adjust: string}
     */
    public static function links(array $data): array
    {
        $difference = Decimal::of((string) $data['difference']);
        $transfer = $difference->isNegative() ? TransactionType::TransferOut : TransactionType::TransferIn;
        $form = fn (TransactionType $type, Decimal $units) => route('transactions.create', [
            'asset' => $data['asset_id'],
            'type' => $type->value,
            'units' => (string) $units,
        ]);

        return [
            'transfer' => $form($transfer, $difference->abs()),
            'transferLabel' => $transfer->label(),
            'adjust' => $form(TransactionType::Adjust, $difference),
        ];
    }
}
