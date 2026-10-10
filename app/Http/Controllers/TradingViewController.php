<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class TradingViewController extends Controller
{
    /**
     * TradingView's embedded chart for a symbol. `?symbol=ETHAUD` chooses it;
     * anything that is not a symbol gives the first on the watchlist.
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $watchlist = $this->watchlist($request);
        $symbol = $request->query('symbol');
        $symbol = is_string($symbol) && preg_match('/^[A-Za-z0-9:._!-]{1,32}$/D', $symbol) ? strtoupper($symbol) : $watchlist[0];

        return view('tradingview', [
            'symbol' => $symbol,
            'watchlist' => $watchlist,
            // TradingView knows fewer timezones than PHP does.
            'timezone' => config('nugget.tradingview.timezones')[$user->timezone] ?? $user->timezone,
        ]);
    }

    /**
     * The markets that price the user's assets, as TradingView writes them
     * (BTC-AUD is BTCAUD), then the configured symbols.
     *
     * @return non-empty-list<string>
     */
    private function watchlist(Request $request): array
    {
        $markets = $request->user()->assets()
            ->where('archived', false)
            ->whereNotNull('market_id')
            ->orderBy('name')
            ->pluck('market_id')
            ->map(fn (string $market) => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $market)));

        return $markets->merge(config('nugget.tradingview.watchlist'))->filter()->unique()->values()->all() ?: ['BTCAUD'];
    }
}
