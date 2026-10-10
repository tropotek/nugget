{{--
    The asset's price chart and its range buttons. Sent alone when htmx asks for #price-chart.
    The buttons are boosted: they swap this element and push the URL. chart.js draws it.
    $points: [time, bid] pairs from App\Prices\PriceChart.
--}}
<div class="card" id="price-chart" hx-boost="true" hx-target="#price-chart" hx-swap="outerHTML" hx-push-url="true">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h4 class="header-title mb-0">Price</h4>
            <div class="btn-group btn-group-sm">
                @foreach (App\Enums\ChartRange::cases() as $choice)
                    <a href="{{ route('assets.show', [$asset, 'range' => $choice->value]) }}"
                        @class(['btn', 'btn-primary' => $choice === $range, 'btn-light' => $choice !== $range])
                        @if ($choice === $range) aria-current="true" @endif>{{ $choice->label() }}</a>
                @endforeach
            </div>
        </div>

        @if ($points)
            <div class="apex-charts" data-chart data-points="{{ json_encode($points) }}"
                data-times="{{ $range->isIntraday() ? 'true' : 'false' }}" data-label="AUD per {{ $asset->unit }}"></div>
        @else
            <p class="text-muted mb-0">No prices in this range.</p>
        @endif
    </div>
</div>
