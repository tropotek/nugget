<?php

namespace App\Api;

use App\Ledger\PortfolioValue;
use App\Ledger\Valuation;
use App\Ledger\Valuations;
use App\Models\User;
use App\Support\Decimal;

/**
 * What a user holds and what it is worth: the dashboard's figures.
 */
class HoldingsData
{
    public function __construct(private readonly Valuations $valuations) {}

    /**
     * Each asset with units held, and the totals of those that count toward
     * the portfolio. `value` and the gains are null for an asset with no
     * price. The totals leave out an asset that is not `in_total` and one
     * with no price.
     *
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $held = $this->valuations->forUser($user)->reject(fn (Valuation $valuation) => $valuation->holding->isEmpty());
        $totals = PortfolioValue::of($held);

        return [
            'currency' => 'AUD',
            'holdings' => $held->values()->map(fn (Valuation $valuation) => [
                'asset' => [
                    ...Format::asset($valuation->holding->asset),
                    'in_total' => $valuation->holding->asset->in_total,
                    'archived' => $valuation->holding->asset->archived,
                ],
                'units' => Format::number($valuation->holding->units),
                'cost_base' => Format::aud($valuation->holding->costBase),
                'average_cost' => Format::number($valuation->holding->averageCost()),
                'cost_is_estimated' => $valuation->holding->estimated,
                'open_parcels' => $valuation->holding->openParcels,
                'price' => $valuation->price ? [
                    'bid' => Format::number($valuation->price->bid),
                    'ask' => Format::number($valuation->price->ask),
                    'as_at' => Format::date($valuation->price->updated_at, $user),
                ] : null,
                'value' => Format::aud($valuation->value()),
                'unrealised_gain' => Format::aud($valuation->gain()),
                'unrealised_gain_percent' => Format::number($valuation->gainPercent()),
            ])->all(),
            'totals' => [
                'value' => Format::aud($totals->value()),
                'cost_base' => Format::aud($totals->costBase),
                'unrealised_gain' => Format::aud($totals->gain()),
                'unrealised_gain_percent' => Format::number($totals->gainPercent()),
                'holdings_without_a_price' => $totals->unpriced,
                'aud_on_exchanges' => Format::aud(Decimal::sum($user->exchanges()->where('active', true)->pluck('aud_balance'))),
            ],
        ];
    }
}
