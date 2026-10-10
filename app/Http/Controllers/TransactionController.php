<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Http\Requests\TransactionRequest;
use App\Ledger\InsufficientUnits;
use App\Ledger\InvalidParcelPick;
use App\Ledger\Ledger;
use App\Models\Asset;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Adding, editing and deleting a transaction by hand. Every change goes
 * through the ledger, and one the ledger cannot support comes back to the
 * form as the `ledger` error.
 */
class TransactionController extends Controller
{
    /**
     * `?asset=ID` chooses the asset to begin with.
     */
    public function create(Request $request): View
    {
        Gate::authorize('create', Transaction::class);

        $transaction = new Transaction(['type' => TransactionType::Buy, 'occurred_at' => now()]);
        $transaction->asset_id = $request->user()->assets()->whereKey($request->integer('asset'))->value('id');

        return $this->form($request, $transaction);
    }

    /** Authorised by TransactionRequest. */
    public function store(TransactionRequest $request, Ledger $ledger): RedirectResponse
    {
        $asset = $request->user()->assets()->findOrFail($request->validated('asset_id'));

        try {
            $transaction = $ledger->record($asset, $request->transactionAttributes());
        } catch (InsufficientUnits|InvalidParcelPick $e) {
            return back()->withInput()->withErrors(['ledger' => $e->getMessage()]);
        }

        return redirect()->route('assets.index')->with('success', $this->describe($transaction).' recorded.');
    }

    public function edit(Request $request, Transaction $transaction): View
    {
        Gate::authorize('update', $transaction);

        return $this->form($request, $transaction);
    }

    /** Authorised by TransactionRequest. */
    public function update(TransactionRequest $request, Transaction $transaction, Ledger $ledger): RedirectResponse
    {
        try {
            $ledger->amend($transaction, $request->transactionAttributes());
        } catch (InsufficientUnits|InvalidParcelPick $e) {
            return back()->withInput()->withErrors(['ledger' => $e->getMessage()]);
        }

        return redirect()->route('assets.index')->with('success', $this->describe($transaction).' saved.');
    }

    /**
     * A transaction from an exchange is never deleted here.
     */
    public function destroy(Transaction $transaction, Ledger $ledger): RedirectResponse
    {
        Gate::authorize('delete', $transaction);
        abort_if($transaction->isLocked(), 403);

        $description = $this->describe($transaction);

        try {
            $ledger->remove($transaction);
        } catch (InsufficientUnits|InvalidParcelPick $e) {
            return redirect()->route('transactions.edit', $transaction->id)->with('error', $e->getMessage());
        }

        return redirect()->route('assets.index')->with('success', "{$description} deleted.");
    }

    /**
     * "Bitcoin: Buy of 0.5 BTC"
     */
    private function describe(Transaction $transaction): string
    {
        $asset = $transaction->asset;

        return "{$asset->name}: {$transaction->type->label()} of {$transaction->units} {$asset->unit}";
    }

    private function form(Request $request, Transaction $transaction): View
    {
        $assets = $request->user()->assets()
            ->where(fn ($query) => $query->where('archived', false)->orWhere('id', $transaction->asset_id))
            ->orderBy('name')
            ->get();

        return view('transactions.form', [
            'transaction' => $transaction,
            'assets' => $assets,
            'types' => TransactionType::cases(),
            'locked' => $transaction->exists && $transaction->isLocked(),
            'totalIsWorkedOut' => ! $transaction->exists || $transaction->total->equals(
                Transaction::totalOf($transaction->type, $transaction->units, $transaction->unit_price, $transaction->fee),
            ),
        ]);
    }
}
