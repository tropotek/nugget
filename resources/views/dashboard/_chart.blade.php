{{--
    The portfolio's value over time and its range buttons. Sent alone when htmx asks for #value-chart.
    The buttons are boosted: they swap this element and push the URL. chart.js draws it.
    $points: [time, value] pairs from App\Ledger\ValueChart.
--}}
<div class="card" id="value-chart" hx-boost="true" hx-target="#value-chart" hx-swap="outerHTML" hx-push-url="true">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h4 class="header-title mb-0">Value</h4>
            <div class="btn-group btn-group-sm">
                @foreach (App\Enums\ChartRange::cases() as $choice)
                    <a href="{{ route('dashboard', ['range' => $choice->value]) }}"
                        @class(['btn', 'btn-primary' => $choice === $range, 'btn-light' => $choice !== $range])
                        @if ($choice === $range) aria-current="true" @endif>{{ $choice->label() }}</a>
                @endforeach
            </div>
        </div>

        @if ($points)
            <div class="apex-charts" data-chart data-points="{{ json_encode($points) }}"
                data-times="{{ $range->isIntraday() ? 'true' : 'false' }}" data-label="Portfolio value"></div>
        @else
            <p class="text-muted mb-0">No prices in this range.</p>
        @endif
    </div>
</div>
