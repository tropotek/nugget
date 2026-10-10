<?php

namespace App\Http\Controllers;

use App\Enums\TransactionType;
use App\Ledger\InsufficientUnits;
use App\Ledger\InvalidParcelPick;
use App\Ledger\Ledger;
use App\Ledger\ParcelChoice;
use App\Ledger\ParcelMatcher;
use App\Models\Transaction;
use App\Rules\Amount;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Matching a sale to parcels by hand, and returning it to the configured method.
 */
class SaleParcelController extends Controller
{
    public function edit(Transaction $transaction, ParcelMatcher $matcher): View
    {
        $sale = $this->sale($transaction);

        return view('transactions.parcels', [
            'sale' => $sale,
            'choices' => ParcelChoice::forSale($sale, $matcher),
        ]);
    }

    /**
     * `picks` is the units to use of each choice, by its key. Empty and zero
     * are the same: none of it.
     */
    public function update(Request $request, Transaction $transaction, ParcelMatcher $matcher, Ledger $ledger): RedirectResponse
    {
        $sale = $this->sale($transaction);
        $unit = $sale->asset->unit;

        $typed = $request->validate(
            ['picks' => ['array'], 'picks.*' => ['nullable', new Amount(Decimal::UNITS_SCALE)]],
            [],
            ['picks.*' => 'units'],
        )['picks'] ?? [];

        $picks = [];
        $errors = [];

        foreach (ParcelChoice::forSale($sale, $matcher) as $key => $choice) {
            $units = Decimal::of($typed[$key] ?? '0');

            if ($units->greaterThan($choice->units)) {
                $errors["picks.{$key}"] = "This parcel has {$choice->units} {$unit} left.";
            } elseif ($units->isPositive()) {
                $picks[] = ['parcel' => $choice->parcel, 'units' => (string) $units];
            }
        }

        $picked = Decimal::sum(array_column($picks, 'units'));

        if (! $errors && ! $picked->equals($sale->units)) {
            $errors['picks'] = "The parcels picked add up to {$picked} {$unit}, but the sale is of {$sale->units} {$unit}.";
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $ledger->pick($sale, $picks);
        } catch (InsufficientUnits|InvalidParcelPick $e) {
            throw ValidationException::withMessages(['picks' => $e->getMessage()]);
        }

        return redirect()->route('transactions.parcels.edit', $sale)->with('success', 'The sale is matched to the parcels you picked.');
    }

    /**
     * "Use default": drop the picks and match the sale by the configured method.
     */
    public function destroy(Transaction $transaction, Ledger $ledger): RedirectResponse
    {
        $sale = $this->sale($transaction);

        try {
            $ledger->unpick($sale);
        } catch (InsufficientUnits|InvalidParcelPick $e) {
            return redirect()->route('transactions.parcels.edit', $sale)->with('error', $e->getMessage());
        }

        return redirect()->route('transactions.parcels.edit', $sale)
            ->with('success', "The sale is matched by the default method: {$sale->fresh()->matching->label()}.");
    }

    /**
     * The transaction, when it is a sale of the user's own.
     */
    private function sale(Transaction $transaction): Transaction
    {
        Gate::authorize('update', $transaction);
        abort_unless($transaction->type === TransactionType::Sell, 404);

        return $transaction;
    }
}
