{{-- No open year has a disposal. $closed: the labels of the closed years that have some. --}}
<x-layouts.app title="Tax report">
    <div class="card">
        <div class="card-body">
            <p class="mb-0">
                Nothing to report yet: nothing has been sold in {{ (new App\Support\FinancialYear((int) App\Facades\Settings::get('first_report_year')))->label() }} or since.
            </p>
            @if ($closed)
                <p class="text-muted mb-0 mt-2">
                    {{ implode(', ', $closed) }} {{ count($closed) === 1 ? 'has' : 'have' }} sales, but {{ count($closed) === 1 ? 'is' : 'are' }} closed:
                    lodged outside Nugget, so no report is offered.
                </p>
            @endif
        </div>
    </div>
</x-layouts.app>
