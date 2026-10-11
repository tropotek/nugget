<?php

namespace App\Http\Controllers;

use App\Enums\AssetClass;
use App\Enums\ChartRange;
use App\Ledger\PortfolioValue;
use App\Ledger\Valuation;
use App\Ledger\Valuations;
use App\Ledger\ValueChart;
use App\Models\Price;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** How many days of prices a holding's sparkline shows. */
    private const SPARKLINE_DAYS = 30;

    /**
     * The signed-in user's portfolio. htmx asks for the totals or the
     * holdings alone, to refresh them as the prices move, and for the chart
     * alone when its range changes.
     */
    public function __invoke(Request $request, Valuations $valuations, ValueChart $chart): View
    {
        $user = $request->user();
        $range = ChartRange::fromInput($request->query('range'));
        $target = $request->header('HX-Target');

        if ($target === 'value-chart') {
            return view('dashboard._chart', ['range' => $range, 'points' => $chart->points($user, $range)]);
        }

        $all = $valuations->forUser($user);

        return match ($target) {
            'dashboard-totals' => view('dashboard._totals', $this->totals($user, $all)),
            'dashboard-holdings' => view('dashboard._holdings', $this->holdings($user, $all)),
            default => view('dashboard.index', [
                ...$this->totals($user, $all),
                ...$this->holdings($user, $all),
                'range' => $range,
                'points' => $chart->points($user, $range),
            ]),
        };
    }

    /**
     * @param  Collection<int, Valuation>  $all
     * @return array<string, mixed>
     */
    private function totals(User $user, Collection $all): array
    {
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

    /**
     * The assets with units held that are shown on the dashboard (not
     * archived, and not switched off it), by class in the order of AssetClass, with each one's bids
     * of the last 30 days, and how many holdings are hidden.
     *
     * @param  Collection<int, Valuation>  $all
     * @return array<string, mixed>
     */
    private function holdings(User $user, Collection $all): array
    {
        $held = $all->reject(fn (Valuation $valuation) => $valuation->holding->isEmpty());
        // What its owner has archived or taken off the dashboard is still held, and still counted in the totals.
        $shown = fn (Valuation $valuation) => $valuation->holding->asset->on_dashboard && ! $valuation->holding->asset->archived;
        $hidden = $held->reject($shown)->count();
        $held = $held->filter($shown);
        $byClass = $held->groupBy(fn (Valuation $valuation) => $valuation->holding->asset->class->value);

        $history = Price::query()
            ->whereIn('asset_id', $held->keys())
            ->daily(now()->subDays(self::SPARKLINE_DAYS), now())
            ->get(['id', 'asset_id', 'at', 'bid'])
            ->groupBy('asset_id')
            ->map(fn (EloquentCollection $prices) => $prices->map(fn (Price $price) => $price->bid)->all());

        return [
            'holdings' => collect(AssetClass::cases())
                ->filter(fn (AssetClass $class) => $byClass->has($class->value))
                ->mapWithKeys(fn (AssetClass $class) => [$class->value => $byClass[$class->value]]),
            'history' => $history,
            'hidden' => $hidden,
        ];
    }
}
