<?php

namespace App\Http\Controllers;

use App\Enums\AssetClass;
use App\Enums\PricedBy;
use App\Http\Requests\AssetRequest;
use App\Ledger\Holding;
use App\Ledger\Holdings;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AssetController extends Controller
{
    /**
     * The user's assets with what they hold of each. Archived assets are
     * left off unless asked for.
     */
    public function index(Request $request, Holdings $holdings): View
    {
        Gate::authorize('viewAny', Asset::class);

        $all = $holdings->forUser($request->user());
        $showArchived = $request->boolean('archived');

        (new Collection($all->pluck('asset')->all()))->load('exchange');

        return view('assets.index', [
            'holdings' => $showArchived ? $all : $all->reject(fn (Holding $holding) => $holding->asset->archived),
            'archivedCount' => $all->filter(fn (Holding $holding) => $holding->asset->archived)->count(),
            'showArchived' => $showArchived,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Asset::class);

        return $this->form($request, new Asset(['class' => AssetClass::Crypto]));
    }

    /** Authorised by AssetRequest. */
    public function store(AssetRequest $request): RedirectResponse
    {
        $asset = $request->user()->assets()->create($request->assetAttributes());

        return redirect()->route('assets.index')->with('success', "Added {$asset->name}.");
    }

    public function edit(Request $request, Asset $asset): View
    {
        Gate::authorize('update', $asset);

        return $this->form($request, $asset);
    }

    /** Authorised by AssetRequest. */
    public function update(AssetRequest $request, Asset $asset): RedirectResponse
    {
        $asset->update($request->assetAttributes());

        return redirect()->route('assets.index')->with('success', "Saved {$asset->name}.");
    }

    /**
     * An asset with transactions is kept: it can be archived instead.
     */
    public function destroy(Asset $asset): RedirectResponse
    {
        Gate::authorize('delete', $asset);

        if ($asset->hasTransactions()) {
            return redirect()->route('assets.edit', $asset)
                ->with('error', "{$asset->name} has transactions, so it cannot be deleted. Archive it instead.");
        }

        $asset->delete();

        return redirect()->route('assets.index')->with('success', "Deleted {$asset->name}.");
    }

    private function form(Request $request, Asset $asset): View
    {
        return view('assets.form', [
            'asset' => $asset,
            'classes' => AssetClass::cases(),
            'sources' => PricedBy::cases(),
            'exchanges' => $request->user()->exchanges()->orderBy('label')->get(),
            'fixed' => $asset->exists && $asset->hasTransactions(),
        ]);
    }
}
