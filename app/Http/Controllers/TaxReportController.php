<?php

namespace App\Http\Controllers;

use App\Enums\AssetClass;
use App\Facades\Settings;
use App\Ledger\ReportYear;
use App\Ledger\ReportYears;
use App\Rules\Amount;
use App\Support\Decimal;
use App\Support\FinancialYear;
use App\Tax\CgtCalculator;
use App\Tax\TaxReportPdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
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
            'classes' => AssetClass::cases(),
        ]);
    }

    /**
     * The same report as a PDF to keep or pass on.
     */
    public function pdf(Request $request, int $year, CgtCalculator $calculator, TaxReportPdf $pdf): RedirectResponse|Response
    {
        if ($refused = $this->refuse($year)) {
            return $refused;
        }

        $report = $calculator->forYear($request->user(), new FinancialYear($year));

        return response($pdf->pdf($request->user(), $report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.TaxReportPdf::filename($report).'"',
        ]);
    }

    /**
     * Save what the user chose for the year's report: the losses carried
     * forward into it and, when the form sends them, the asset classes it
     * takes in. Every class chosen is saved as no choice at all.
     */
    public function update(Request $request, int $year): RedirectResponse
    {
        if ($refused = $this->refuse($year)) {
            return $refused;
        }

        // The form sends a blank with the boxes, so that none ticked is told from not sent.
        if (is_array($request->input('asset_classes'))) {
            $request->merge(['asset_classes' => array_values(array_filter($request->input('asset_classes'), fn (mixed $class) => $class !== null && $class !== ''))]);
        }

        $chosen = $request->validate(
            [
                'losses_carried_forward' => ['required', new Amount(Decimal::AUD_SCALE)],
                'asset_classes' => ['sometimes', 'array', 'min:1'],
                'asset_classes.*' => ['distinct', Rule::enum(AssetClass::class)],
            ],
            ['asset_classes.min' => 'Choose at least one asset class for the report.'],
            ['losses_carried_forward' => 'losses carried forward', 'asset_classes' => 'asset classes'],
        );

        $save = ['losses_carried_forward' => Decimal::of((string) $chosen['losses_carried_forward'])];

        if (isset($chosen['asset_classes'])) {
            $save['asset_classes'] = count($chosen['asset_classes']) === count(AssetClass::cases()) ? null : $chosen['asset_classes'];
        }

        $request->user()->taxYears()->updateOrCreate(['year' => $year], $save);

        return redirect()->route('tax-report.show', $year)->with('success', 'Saved the choices for '.(new FinancialYear($year))->label().'.');
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
