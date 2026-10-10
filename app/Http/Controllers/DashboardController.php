<?php

namespace App\Http\Controllers;

use App\Enums\ChartRange;
use App\Ledger\PortfolioValue;
use App\Ledger\Valuation;
use App\Ledger\Valuations;
use App\Ledger\ValueChart;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The signed-in user's portfolio. htmx asks for the totals alone, to
     * refresh them as the prices move, and for the chart alone when its
     * range changes.
     */
    public function __invoke(Request $request, Valuations $valuations, ValueChart $chart): View
    {
        $user = $request->user();
        $range = ChartRange::fromInput($request->query('range'));
        $target = $request->header('HX-Target');

        if ($target === 'value-chart') {
            return view('dashboard._chart', ['range' => $range, 'points' => $chart->points($user, $range)]);
        }

        $totals = $this->totals($user, $valuations);

        if ($target === 'dashboard-totals') {
            return view('dashboard._totals', $totals);
        }

        return view('dashboard.index', [...$totals, 'range' => $range, 'points' => $chart->points($user, $range)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(User $user, Valuations $valuations): array
    {
        $all = $valuations->forUser($user);

        return [
            'totals' => PortfolioValue::of($all),
            // An inactive exchange is not synced, so its balance is not known to be there.
            'audOnExchanges' => Decimal::sum($user->exchanges()->where('active', true)->pluck('aud_balance')),
            // When the oldest of the prices behind the totals was read.
            'pricedAt' => $all
                ->filter(fn (Valuation $valuation) => $valuation->isPriced() && $valuation->holding->asset->in_total && ! $valuation->holding->isEmpty())
                ->min(fn (Valuation $valuation) => $valuation->price->updated_at),
        ];
    }
}
