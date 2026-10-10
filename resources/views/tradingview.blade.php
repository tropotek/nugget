{{--
    TradingView's embedded chart. resources/js/tradingview.js loads their script and builds the
    widget from the data attributes, and builds it again when the light and dark switch is used.
    $watchlist: the symbols offered. $timezone: the user's, as TradingView names it.
--}}
<x-layouts.app title="TradingView">
    <x-slot:actions>
        <form method="GET" action="{{ route('tradingview') }}" class="d-flex gap-2">
            <label for="symbol" class="visually-hidden">Symbol</label>
            <input type="text" id="symbol" name="symbol" value="{{ $symbol }}" list="tradingview-watchlist" maxlength="32" required
                class="form-control" style="width: 10rem" autocomplete="off" placeholder="BTCAUD">
            <datalist id="tradingview-watchlist">
                @foreach ($watchlist as $listed)
                    <option value="{{ $listed }}"></option>
                @endforeach
            </datalist>
            <button type="submit" class="btn btn-primary">Show</button>
        </form>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                @foreach ($watchlist as $listed)
                    <a href="{{ route('tradingview', ['symbol' => $listed]) }}" @class(['btn', 'btn-sm', 'btn-primary' => $listed === $symbol, 'btn-light' => $listed !== $symbol])>{{ $listed }}</a>
                @endforeach
            </div>

            <div class="tradingview-widget-container">
                <div id="tradingview-chart" style="height: 700px" data-tradingview data-symbol="{{ $symbol }}"
                    data-timezone="{{ $timezone }}" data-watchlist="{{ json_encode($watchlist) }}"></div>
                <div class="form-text mt-2">
                    <a href="https://www.tradingview.com/symbols/{{ rawurlencode($symbol) }}/" rel="noopener" target="_blank">{{ $symbol }} chart</a> by TradingView.
                    The chart and its prices come from TradingView, not from Nugget's own prices.
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
