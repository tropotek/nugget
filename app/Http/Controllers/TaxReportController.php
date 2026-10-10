<?php

namespace App\Http\Controllers;

use App\Facades\Settings;
use App\Ledger\ReportYear;
use App\Ledger\ReportYears;
use App\Rules\Amount;
use App\Support\Decimal;
use App\Support\FinancialYear;
use App\Tax\CgtCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The signed-in user's own capital gains report, a financial year at a time.
 * Only an open year has one: the `first_report_year` setting or later.
 */
class TaxReportController extends Controller
{
    public function __construct(private readonly ReportYears $years) {}

    /**
     * The latest open year that has disposals, or a page that says there is nothing to report.
     */
    public function index(Request $request): RedirectResponse|View
    {
        $latest = $this->years->open($request->user())->first();

        // A message sent here, such as that a year is closed, goes on to the year this opens.
        $request->session()->reflash();

        return $latest
            ? redirect()->route('tax-report.show', $latest->year->year)
            : view('tax-report.empty', ['closed' => $this->closed($request)]);
    }

    public function show(Request $request, int $year, CgtCalculator $calculator): RedirectResponse|View
    {
        if ($refused = $this->refuse($year)) {
            return $refused;
        }

        $user = $request->user();
        $financialYear = new FinancialYear($year);
        $before = new FinancialYear($year - 1);

        return view('tax-report.show', [
            'year' => $financialYear,
            'report' => $calculator->forYear($user, $financialYear),
            'years' => $this->years->open($user),
            'closed' => $this->closed($request),
            // What the year before leaves to carry forward, as a prompt for the field. Nothing is filled in.
            'broughtForward' => $this->isOpen($year - 1) ? $calculator->forYear($user, $before)->summary->lossCarriedForward : null,
            'before' => $before,
        ]);
    }

    /**
     * Save the losses carried forward into the year.
     */
    public function update(Request $request, int $year): RedirectResponse
    {
        if ($refused = $this->refuse($year)) {
            return $refused;
        }

        $request->validate(
            ['losses_carried_forward' => ['required', new Amount(Decimal::AUD_SCALE)]],
            attributes: ['losses_carried_forward' => 'losses carried forward'],
        );

        $request->user()->taxYears()->updateOrCreate(
            ['year' => $year],
            ['losses_carried_forward' => Decimal::of((string) $request->input('losses_carried_forward'))],
        );

        return redirect()->route('tax-report.show', $year)->with('success', 'Saved the losses carried forward into '.(new FinancialYear($year))->label().'.');
    }

    /**
     * A year that could not be one is not found. A closed year goes back to
     * the start with the reason.
     */
    private function refuse(int $year): ?RedirectResponse
    {
        abort_unless($year >= 2000 && $year <= Settings::currentYear() + 1, 404);

        if ($this->isOpen($year)) {
            return null;
        }

        return redirect()->route('tax-report.index')
            ->with('warning', (new FinancialYear($year))->label().' is closed: it was lodged outside Nugget, so there is no report for it.');
    }

    private function isOpen(int $year): bool
    {
        return $year >= (int) Settings::get('first_report_year');
    }

    /**
     * The closed years that have disposals, as their labels, the latest first.
     *
     * @return list<string>
     */
    private function closed(Request $request): array
    {
        return $this->years->forUser($request->user())
            ->reject(fn (ReportYear $year) => $year->open)
            ->map(fn (ReportYear $year) => $year->year->label())
            ->values()
            ->all();
    }
}
