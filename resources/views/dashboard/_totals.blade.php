{{--
    The headline tiles. Sent alone when htmx asks for #dashboard-totals, which it does every minute
    so that the figures follow the prices without a page reload.
    $totals: an App\Ledger\PortfolioValue. $audOnExchanges: a Decimal. $pricedAt: when the oldest price used was read, or null.
--}}
<div id="dashboard-totals" hx-get="{{ route('dashboard') }}" hx-trigger="every 60s" hx-target="#dashboard-totals" hx-swap="outerHTML">
    <div class="row">
        <div class="col-sm-6 col-xl-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">Total value</h5>
                    <h3 class="mb-0">${{ $totals->value()->format() }}</h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">Cost base</h5>
                    <h3 class="mb-0">${{ $totals->costBase->format() }}</h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">Unrealised gain</h5>
                    <h3 class="mb-0"><x-gain :value="$totals->gain()" :percent="$totals->gainPercent()" /></h3>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card">
                <div class="card-body">
                    <h5 class="text-muted fw-normal mt-0">AUD on exchanges</h5>
                    <h3 class="mb-0">${{ $audOnExchanges->format() }}</h3>
                </div>
            </div>
        </div>
    </div>

    <ul class="list-inline text-muted">
        @if ($pricedAt)
            <li class="list-inline-item me-3">Prices as at <x-date :value="$pricedAt" /></li>
        @endif
        @if ($totals->unpriced > 0)
            <li class="list-inline-item me-3">
                {{ $totals->unpriced === 1 ? '1 holding has no price and is' : $totals->unpriced.' holdings have no price and are' }} left out of these figures
            </li>
        @endif
        <li class="list-inline-item">The AUD on exchanges is not part of the total value</li>
    </ul>
</div>
