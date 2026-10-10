<?php

namespace App\Http\Controllers;

use App\Exchanges\Contracts\ChecksConnection;
use App\Exchanges\ExchangeDrivers;
use App\Exchanges\Failure;
use App\Http\Requests\ExchangeRequest;
use App\Models\Exchange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class ExchangeController extends Controller
{
    public function __construct(private readonly ExchangeDrivers $drivers) {}

    /**
     * The user's exchange accounts, with how the last sync of each went.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Exchange::class);

        return view('exchanges.index', [
            'exchanges' => $request->user()->exchanges()->withCount('assets')->orderBy('label')->orderBy('id')->get(),
            'drivers' => $this->drivers,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Exchange::class);

        return $this->form(new Exchange);
    }

    /** Authorised by ExchangeRequest. */
    public function store(ExchangeRequest $request): RedirectResponse
    {
        $exchange = $request->user()->exchanges()->create($request->exchangeAttributes());

        return redirect()->route('exchanges.edit', $exchange)
            ->with('success', "Added {$exchange->label}. Test the connection to check its key.");
    }

    public function edit(Exchange $exchange): View
    {
        Gate::authorize('update', $exchange);

        return $this->form($exchange);
    }

    /** Authorised by ExchangeRequest. */
    public function update(ExchangeRequest $request, Exchange $exchange): RedirectResponse
    {
        $exchange->update($request->exchangeAttributes());

        return redirect()->route('exchanges.index')->with('success', "Saved {$exchange->label}.");
    }

    /**
     * An exchange that prices an asset is kept: it can be made inactive instead.
     */
    public function destroy(Exchange $exchange): RedirectResponse
    {
        Gate::authorize('delete', $exchange);

        if ($exchange->assets()->exists()) {
            return redirect()->route('exchanges.edit', $exchange)
                ->with('error', "{$exchange->label} prices an asset, so it cannot be deleted. Make it inactive instead.");
        }

        $exchange->delete();

        return redirect()->route('exchanges.index')->with('success', "Deleted {$exchange->label}.");
    }

    /**
     * Try the saved key and secret against the exchange, and say how it went.
     * Nothing is saved: the last sync's time and error are the sync's to write.
     */
    public function test(Exchange $exchange): RedirectResponse
    {
        Gate::authorize('update', $exchange);

        $back = redirect()->back(fallback: route('exchanges.edit', $exchange));

        try {
            $driver = $this->drivers->for($exchange);

            if (! $driver instanceof ChecksConnection) {
                return $back->with('warning', "The connection to {$exchange->label} cannot be tested: its driver has no call for it.");
            }

            $driver->checkConnection();
        } catch (Throwable $e) {
            return $back->with('error', "{$exchange->label} did not connect: ".Failure::reason($e));
        }

        return $back->with('success', "{$exchange->label} connected: the exchange accepted the key.");
    }

    private function form(Exchange $exchange): View
    {
        return view('exchanges.form', [
            'exchange' => $exchange,
            'drivers' => $this->drivers,
            'usedByAssets' => $exchange->exists && $exchange->assets()->exists(),
        ]);
    }
}
