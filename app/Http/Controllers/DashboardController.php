<?php

namespace App\Http\Controllers;

use App\Ledger\PortfolioValue;
use App\Ledger\Valuation;
use App\Ledger\Valuations;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The signed-in user's portfolio. htmx asks for the totals alone, to
     * refresh them as the prices move.
     */
    public function __invoke(Request $request, Valuations $valuations): View
    {
        $user = $request->user();
        $all = $valuations->forUser($user);

        $totals = [
            'totals' => PortfolioValue::of($all),
            // An inactive exchange is not synced, so its balance is not known to be there.
            'audOnExchanges' => Decimal::sum($user->exchanges()->where('active', true)->pluck('aud_balance')),
            // When the oldest of the prices behind the totals was read.
            'pricedAt' => $all
                ->filter(fn (Valuation $valuation) => $valuation->isPriced() && $valuation->holding->asset->in_total && ! $valuation->holding->isEmpty())
                ->min(fn (Valuation $valuation) => $valuation->price->updated_at),
        ];

        return $request->header('HX-Target') === 'dashboard-totals'
            ? view('dashboard._totals', $totals)
            : view('dashboard.index', $totals);
    }
}
